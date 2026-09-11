<?php

namespace App\Agent;

use App\Connectors\Contracts\JiraConnector;
use App\Connectors\Contracts\NotionConnector;
use App\Connectors\Contracts\SlackConnector;
use App\Models\AgentAction;
use App\Models\Investigation;
use App\Reporting\InvestigationReport;

/**
 * The write phase: Slack channel, Jira ticket, Notion write-up.
 *
 * These are planned deterministically rather than chosen by the model. What the
 * agent concluded is a judgement; whether an incident gets a channel and a
 * ticket is policy, and policy should not vary with sampling temperature.
 *
 * Every write is staged as `pending_approval` first. A human releases it, unless
 * auto-approval is explicitly configured for a live demo. Nothing reaches a real
 * workspace as a side effect of an investigation running.
 */
class ActionExecutor
{
    public function __construct(
        private readonly SlackConnector $slack,
        private readonly JiraConnector $jira,
        private readonly NotionConnector $notion,
    ) {}

    /** @return array<int,AgentAction> the staged (and possibly executed) actions */
    public function plan(Investigation $investigation): array
    {
        $report = InvestigationReport::for($investigation);
        $sequence = (int) AgentAction::where('investigation_id', $investigation->id)->max('sequence');

        $originChannel = $investigation->incident->slack_channel_id;

        $planned = [
            ['tool' => 'slack_open_incident_channel', 'arguments' => [
                'channel' => $report->channelName(),
                'purpose' => $report->title(),
                // An investigation asked for in Slack reports back to the thread
                // that asked; only an autonomously detected one needs its own channel.
                'reply_to_channel' => $originChannel,
            ]],
            ['tool' => 'jira_file_incident', 'arguments' => [
                'summary' => $report->title(),
                'labels' => ['incident', 'agent-filed', $investigation->incident->severity],
            ]],
            ['tool' => 'notion_write_report', 'arguments' => [
                'title' => $report->title(),
            ]],
        ];

        $actions = [];

        foreach ($planned as $spec) {
            $actions[] = AgentAction::create([
                'investigation_id' => $investigation->id,
                'sequence' => ++$sequence,
                'tool' => $spec['tool'],
                'phase' => 'act',
                'arguments' => $spec['arguments'],
                'status' => 'pending_approval',
                'was_write' => true,
                'occurred_at' => now(),
            ]);
        }

        if (config('agent.auto_approve_writes')) {
            foreach ($actions as $action) {
                $this->execute($action, approvedBy: 'auto-approve');
            }
        }

        return $actions;
    }

    public function execute(AgentAction $action, string $approvedBy = 'operator'): AgentAction
    {
        if (! $action->was_write) {
            throw new \LogicException('Only write actions pass through the approval gate.');
        }

        if ($action->status === 'succeeded') {
            return $action;
        }

        $investigation = $action->investigation;
        $report = InvestigationReport::for($investigation);
        $startedAt = microtime(true);

        try {
            $result = match ($action->tool) {
                'slack_open_incident_channel' => $this->openSlackChannel($action, $report),
                'jira_file_incident' => $this->fileJiraIssue($investigation, $report),
                'notion_write_report' => $this->writeNotionPage($report),
                default => throw new \InvalidArgumentException("Unknown write action [{$action->tool}]."),
            };

            $action->update([
                'status' => 'succeeded',
                'result_summary' => $result->summary,
                'result' => $result->payload,
                'external_ref' => $result->externalRef,
                'external_url' => $result->externalUrl,
                'approved_at' => now(),
                'approved_by' => $approvedBy,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $action->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'approved_at' => now(),
                'approved_by' => $approvedBy,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        }

        return $action->fresh();
    }

    public function reject(AgentAction $action, string $by = 'operator'): AgentAction
    {
        $action->update(['status' => 'rejected', 'approved_by' => $by, 'approved_at' => now()]);

        return $action;
    }

    private function openSlackChannel(AgentAction $action, InvestigationReport $report): WriteAction
    {
        if ($replyTo = ($action->arguments['reply_to_channel'] ?? null)) {
            $message = $this->slack->postMessage($replyTo, $report->slackSummary());

            return new WriteAction(
                summary: 'Posted the findings back to the channel that asked',
                externalRef: $replyTo,
                externalUrl: $message['url'] ?? null,
                payload: ['channel' => $replyTo, 'message' => $message],
            );
        }

        $channel = $this->slack->createChannel(
            $action->arguments['channel'] ?? $report->channelName(),
            $action->arguments['purpose'] ?? $report->title(),
        );

        $message = $this->slack->postMessage($channel['id'], $report->slackSummary());

        return new WriteAction(
            summary: "Opened #{$channel['name']} and posted the investigation summary",
            externalRef: $channel['id'],
            externalUrl: $message['url'] ?? $channel['url'],
            payload: ['channel' => $channel, 'message' => $message],
        );
    }

    private function fileJiraIssue(Investigation $investigation, InvestigationReport $report): WriteAction
    {
        $description = $report->markdown();

        // Link the ticket to the suspected commit, as promised in the brief.
        if ($sha = $report->suspectSha()) {
            $repo = config('connectors.github.repo') ?: 'acme/payments-api';
            $description .= "\n\n### Suspected commit\n\nhttps://github.com/{$repo}/commit/{$sha}";
        }

        $issue = $this->jira->createIssue(
            $report->title(),
            $description,
            ['incident', 'agent-filed', $investigation->incident->severity],
        );

        return new WriteAction(
            summary: "Filed {$issue['key']}",
            externalRef: $issue['key'],
            externalUrl: $issue['url'],
            payload: $issue,
        );
    }

    private function writeNotionPage(InvestigationReport $report): WriteAction
    {
        $page = $this->notion->createPage($report->title(), $report->markdown());

        return new WriteAction(
            summary: 'Published the investigation write-up to Notion',
            externalRef: $page['id'],
            externalUrl: $page['url'],
            payload: $page,
        );
    }
}
