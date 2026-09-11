<?php

namespace App\Connectors;

/**
 * Field metadata for every connector.
 *
 * One definition drives the settings form, the config mapping, and the
 * connection test, so adding a connector does not mean editing a form by hand
 * and then forgetting to update the other two.
 *
 * Field names match their `config('connectors.<key>.<field>')` path exactly.
 */
class ConnectorRegistry
{
    public static function all(): array
    {
        return [
            'github' => [
                'label' => 'GitHub',
                'icon' => 'heroicon-o-code-bracket',
                'description' => 'Deployments, commits and diffs — the evidence root-cause correlation depends on.',
                'docs' => 'https://github.com/settings/tokens',
                'fields' => [
                    [
                        'name' => 'token', 'label' => 'Personal access token', 'secret' => true, 'required' => true,
                        'placeholder' => 'ghp_…',
                        'help' => 'Needs repo:read. Fine-grained tokens need Contents and Deployments read.',
                        // Pre-selects the scope and names the token, so the page
                        // opens ready to click Generate.
                        'docs_url' => 'https://github.com/settings/tokens/new?scopes=repo&description=Connector%20incident%20agent',
                        'docs_label' => 'Create a token with repo scope',
                        'where' => 'GitHub → Settings → Developer settings → Personal access tokens',
                    ],
                    [
                        'name' => 'repo', 'label' => 'Repository', 'secret' => false, 'required' => true,
                        'placeholder' => 'owner/repo',
                        'help' => 'The repository whose deploys are correlated against incidents.',
                        'docs_url' => 'https://github.com/settings/repositories',
                        'docs_label' => 'See repositories you can access',
                        'where' => 'The owner/name pair from the repository URL',
                    ],
                ],
            ],

            'slack' => [
                'label' => 'Slack',
                'icon' => 'heroicon-o-chat-bubble-left-right',
                'description' => 'Creates the incident channel and posts the investigation summary.',
                'docs' => 'https://api.slack.com/apps',
                'fields' => [
                    [
                        'name' => 'bot_token', 'label' => 'Bot user OAuth token', 'secret' => true, 'required' => true,
                        'placeholder' => 'xoxb-…',
                        'help' => 'Scopes: channels:manage, chat:write. Install the app to your workspace, then copy the bot token.',
                        'docs_url' => 'https://api.slack.com/apps?new_app=1',
                        'docs_label' => 'Create a Slack app',
                        'where' => 'Your app → OAuth & Permissions → Bot User OAuth Token',
                    ],
                    [
                        'name' => 'signing_secret', 'label' => 'Signing secret', 'secret' => true, 'required' => false,
                        'placeholder' => '32-character secret',
                        'help' => 'Only needed for the /investigate slash command. Without it the inbound '
                            .'endpoint rejects every request, which is the safe default.',
                        'docs_url' => 'https://api.slack.com/apps',
                        'docs_label' => 'Find your signing secret',
                        'where' => 'Your app → Basic Information → App Credentials → Signing Secret',
                    ],
                    [
                        'name' => 'channel_prefix', 'label' => 'Channel prefix', 'secret' => false, 'required' => false,
                        'placeholder' => 'inc-',
                        'help' => 'Prepended to the incident reference when naming the channel.',
                        'docs_url' => null, 'docs_label' => null,
                        'where' => null,
                    ],
                ],
            ],

            'jira' => [
                'label' => 'Jira',
                'icon' => 'heroicon-o-ticket',
                'description' => 'Files the incident ticket with evidence and the linked commit.',
                'docs' => 'https://id.atlassian.com/manage-profile/security/api-tokens',
                'fields' => [
                    [
                        'name' => 'base_url', 'label' => 'Site URL', 'secret' => false, 'required' => true,
                        'placeholder' => 'https://you.atlassian.net',
                        'help' => 'No trailing path.',
                        'docs_url' => 'https://admin.atlassian.com/',
                        'docs_label' => 'Find your site URL',
                        'where' => 'The host part of any Jira URL you use',
                    ],
                    [
                        'name' => 'email', 'label' => 'Account email', 'secret' => false, 'required' => true,
                        'placeholder' => 'you@company.com',
                        'help' => 'The account the API token belongs to.',
                        'docs_url' => 'https://id.atlassian.com/manage-profile/profile-and-visibility',
                        'docs_label' => 'Your Atlassian profile',
                        'where' => null,
                    ],
                    [
                        'name' => 'api_token', 'label' => 'API token', 'secret' => true, 'required' => true,
                        'placeholder' => 'ATATT…',
                        'help' => 'Create a token, then copy it — Atlassian shows it only once.',
                        'docs_url' => 'https://id.atlassian.com/manage-profile/security/api-tokens',
                        'docs_label' => 'Create an API token',
                        'where' => 'Atlassian account → Security → API tokens',
                    ],
                    [
                        'name' => 'project_key', 'label' => 'Project key', 'secret' => false, 'required' => true,
                        'placeholder' => 'INC',
                        'help' => 'Where incident tickets are created. The short prefix in issue keys like INC-42.',
                        // Built from the site URL already entered, so the link
                        // goes to the operator's own project list.
                        'docs_url' => 'https://your-site.atlassian.net/jira/projects',
                        'docs_label' => 'Browse your projects',
                        'where' => 'Jira → Projects → the Key column',
                        'docs_from_base_url' => '/jira/projects',
                    ],
                    [
                        'name' => 'issue_type', 'label' => 'Issue type', 'secret' => false, 'required' => false,
                        'placeholder' => 'Task',
                        'help' => 'Must exist in that project.',
                        'docs_url' => null, 'docs_label' => null,
                        'where' => 'Project settings → Issue types',
                    ],
                ],
            ],

            'notion' => [
                'label' => 'Notion',
                'icon' => 'heroicon-o-document-text',
                'description' => 'Publishes the investigation write-up.',
                'docs' => 'https://www.notion.so/my-integrations',
                'fields' => [
                    [
                        'name' => 'token', 'label' => 'Internal integration secret', 'secret' => true, 'required' => true,
                        'placeholder' => 'ntn_… or secret_…',
                        'help' => 'Create an internal integration, then copy its secret.',
                        'docs_url' => 'https://www.notion.so/my-integrations',
                        'docs_label' => 'Create an integration',
                        'where' => 'Notion → My integrations → New integration → Secrets',
                    ],
                    [
                        'name' => 'parent_page_id', 'label' => 'Parent page ID', 'secret' => false, 'required' => true,
                        'placeholder' => '32-character page id',
                        'help' => 'The 32 characters at the end of the page URL. The integration must also be '
                            .'shared with this page, or writes return 404.',
                        'docs_url' => 'https://www.notion.so/help/add-and-manage-connections-with-the-api',
                        'docs_label' => 'How to share a page with an integration',
                        'where' => 'Open the page → ••• → Connections → add your integration',
                    ],
                ],
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<int,string> */
    public static function fieldNames(string $key): array
    {
        return array_column(self::get($key)['fields'] ?? [], 'name');
    }

    /** @return array<int,string> */
    public static function secretFields(string $key): array
    {
        return array_column(
            array_filter(self::get($key)['fields'] ?? [], fn ($f) => $f['secret']),
            'name',
        );
    }

    /** @return array<int,string> */
    public static function requiredFields(string $key): array
    {
        return array_column(
            array_filter(self::get($key)['fields'] ?? [], fn ($f) => $f['required']),
            'name',
        );
    }
}
