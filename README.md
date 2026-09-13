# Connector — incident response agent

Turns a vague alert into a documented investigation: pulls latency data, correlates it against
deployments and commits, opens a Slack incident channel, files a Jira ticket, and publishes a
Notion write-up.

**The agent never asserts a cause in prose.** Every conclusion is a structured object — a
hypothesis, the specific evidence supporting it, a confidence score, and a log of what was
actually done — so each claim can be checked against the source it came from.

That guardrail is not decorative. Asked for a hypothesis with no constraints, the local model
produced this unprompted:

```json
"evidence_ids": ["latency_log_abc123_01", "api_call_latency_abc123_02"]
```

Every one of those IDs is invented. Rejecting citations like these is the core of the system.

## How it works

```
chaos scenario  →  /healthz degrades  →  pinger records samples  →  detector opens an incident
                                                                              ↓
        Slack + Jira + Notion  ←  validated hypotheses  ←  agent investigates
             (held for approval)
```

**Four phases**, in deliberate order:

| Phase | What happens |
|---|---|
| **gather** | Deterministic. PHP computes the latency changepoint and scores every deploy by timing and changed-path relevance. Evidence exists before the model is consulted. |
| **explore** | The model pulls extra facts through read-only tools. Every call is logged; everything returned joins the ledger. |
| **synthesize** | Hypotheses under a strict JSON schema, then validated against the ledger. |
| **act** | Slack, Jira and Notion writes — staged for approval, never automatic. |

### Deterministic correlation, LLM narration

A 4B model asked to locate a changepoint across hundreds of samples and match it to a deploy will
confabulate plausible arithmetic. So `App\Agent\Correlator` establishes *what happened and when* in
PHP, and the model is left with the interpretive part: which candidate explains it, and how much
confidence that deserves. This is what makes a local 4B model viable here.

### Every conclusion carries its mechanism and its next step

A hypothesis is not just a claim. Alongside `statement` it carries `mechanism` — what in the
changed code produces the symptom — and `remediation`, the next action a responder should take.

Both are schema fields rather than prompt requests, and that distinction was learned the hard way.
With the giveaway prose removed from the scenarios, the model started emitting bare assertions:
*"Deploy 7b0ecdb caused the latency spike."* Strengthening the prompt changed nothing. A
grammar-enforced field cannot be skipped the way an instruction can, and the output changed
immediately:

> **Mechanism.** The change removes a 5-second lock and shortens the cache lifetime from 3600s to
> 60s. Without the lock the recomputation runs synchronously in the request path, so every request
> blocks until the rate table is rebuilt.
>
> **Suggested next step.** Roll back deploy 2dcdb73 to restore the original cache lifetime of
> 3600 seconds and validate that p95 returns to baseline (38ms).

The same lesson applied to `confidence`. The model read it as certainty in its own reasoning and
put **0.99 on a ruled-out candidate**, inverting the number and corrupting the Brier score. The
description said otherwise; the field name won. Renaming it to
`probability_this_explains_the_incident` fixed it on the next run.

Remediation prefers the reversible option — reverting a deploy over designing a fix mid-incident —
and is constrained to things visible in the evidence, so it cannot invent commands or paths.

### Stance: causes and exclusions

A hypothesis carries a `stance` — `cause` or `ruled_out`. Without it the schema offered only a
causal frame, so the model expressed exclusions as *"the latency spike is caused by deploy X"* at
0.00 confidence: a statement that contradicts itself and read badly in every report it reached.
Confidence keeps one meaning throughout — the probability that this explains the incident — so a
ruled-out candidate simply sits near zero rather than needing a second scale to learn.

Scoring and the report both take the leading **cause**; an exclusion is never the finding, however
firmly it is held.

### The evidence ledger

Nothing reaches the model that has not first been written to `App\Agent\EvidenceLedger` and given a
public handle (`EV-1`, `EV-2`, …). The set of legitimate citations is therefore fixed *before*
generation, so any identifier outside it is provably invented. `App\Agent\EvidenceValidator` applies
two gates:

- **Existence** — a cited ID must belong to this investigation. Failing this is fatal: one repair
  turn naming the bad IDs, then the claim is stored as `rejected_unsupported` and shown in the UI as
  discarded. Rejected claims are kept on purpose; a board that quietly cleaned itself would defeat
  the point.
- **Grounding** — figures in the statement must be traceable to the cited payload, with a 5%
  tolerance so sensible rounding still passes. Partial grounding is scored, not fatal; a statement
  whose figures are *all* invented is rejected.

## Setup

Requires PHP 8.4, PostgreSQL, Redis, and [Jan](https://jan.ai) running with its local API server on.

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
createdb connector
php artisan migrate --seed
```

Sign in at `/admin` with `heinrich@quickorganics.com` / `password`.

### Connectors

Configure them at **`/admin/connectors`**. Credentials are stored in the database, encrypted with
the app key, so a connector can be wired up or switched to its fake without editing a file or
restarting anything.

Every connector ships an `api` and a `fake` driver. Fakes keep the whole loop — and every eval run —
working offline, which matters when a token expires before a demo. A driver set to `api` with
incomplete credentials falls back to its fake rather than failing mid-investigation.

```bash
php artisan connectors status      # drivers, missing fields, and whether each value came from db or env
php artisan connectors test all    # read-only identity checks against the live APIs
```

**Precedence, per field:** database → `.env` → nothing. A field left blank in the UI falls back to
the environment, so seeding from `.env` still works and an existing deployment keeps running
unchanged.

```dotenv
GITHUB_DRIVER=api    GITHUB_TOKEN=...   GITHUB_REPO=owner/repo
SLACK_DRIVER=api     SLACK_BOT_TOKEN=xoxb-...
JIRA_DRIVER=api      JIRA_BASE_URL=https://you.atlassian.net  JIRA_EMAIL=...  JIRA_API_TOKEN=...  JIRA_PROJECT_KEY=INC
NOTION_DRIVER=api    NOTION_TOKEN=secret_...  NOTION_PARENT_PAGE_ID=...
```

Handling of secrets:

- Encrypted at rest in `connector_settings`; a database dump alone does not expose them.
- Never rendered back to the browser. A saved secret shows as a masked placeholder, and submitting
  it blank keeps the stored value — so editing an unrelated field cannot wipe a working token.
- **Test connection** performs read-only identity checks only (`auth.test`, `/user`, `/myself`,
  `/users/me`). It never creates a channel, ticket or page.
- Rotating `APP_KEY` makes stored credentials unreadable; they must be re-entered.

## Demo

```bash
./bin/demo
```

Starts everything the demo needs — Redis, the local model server, the web server, the queue worker
and the scheduler — skipping whatever is already up and reporting plainly when something failed.
Five processes have to be running for an investigation to complete, and during development every
one of them went down at least once.

The equivalent by hand:

```bash
php artisan serve
php artisan queue:work        # required — the UI dispatches investigations to the queue
php artisan schedule:work     # required for automated monitoring; drives polling and detection
```

**Neither background process is optional.** Investigating from the UI dispatches a job; without a
worker the run sits queued forever (the live panel detects this and says so rather than spinning).
Without the scheduler, nothing is polled and no incident is ever opened on its own.
`php artisan investigate` runs synchronously and needs neither.

### Cadence

Set at **`/admin/schedule`**, stored in the database:

| | |
|---|---|
| Poll `/healthz` every | 5s – 60s |
| Evaluate for incidents every | 1 – 30 min |
| Investigate automatically | on / off |
| Ceiling on automated runs | 1 – 30 per hour |
| Repeat-incident cooldown | 5 – 60 min |
| Quiet hours | optional UTC window, wraps midnight |

The same page shows the **observed** rate beside the configured one — runs this hour against the
cap, per-hour histogram for the last 24h, mean gap between runs, mean duration. Configured cadence
is an intention; the observed rate is what actually happened, and the gap between them is how you
notice the hourly cap quietly swallowing investigations.

The hourly ceiling is the important one: each run is roughly 25s of local inference and several
thousand tokens, so a flapping endpoint could otherwise queue runs faster than the worker drains
them. When the cap is hit, incidents are still opened — only the automatic investigation is skipped,
and the page says so.

Laravel's scheduler is fixed at boot and cannot read a changing interval, so both commands are
scheduled every minute and the configured interval is enforced inside each one. That is what lets
the cadence change from the UI with no restart.

```bash
php artisan health:ping --scheduled     # what the scheduler runs
php artisan health:detect --scheduled
php artisan health:ping --watch         # standalone watcher, ignores the schedule
```

Then, either from **Chaos control** in the UI or the CLI:

```bash
php artisan demo:seed slow-query --fresh   # culprit deploy + decoys + 35 min of history
php artisan health:detect                  # opens an incident, queues the investigation
php artisan investigate                    # or run it synchronously and print the findings
php artisan actions list                   # staged writes
php artisan actions approve all            # release them
```

Staging a scenario writes the culprit deploy *and* flips the chaos profile in one act, so ground
truth exists for free and every demo run is a scored case rather than an anecdote.

### Starting an investigation from a prompt

**New investigation** on the incidents list takes a plain-language prompt — "investigate the
payment API latency spike" — picks the service and window, and runs the agent. The prompt is passed
to the agent untouched, but the incident is stamped with the same measured signal the detector
would have attached, so the reasoning runs on real numbers rather than on the phrasing of the
request.

### Live view

Investigating from the UI opens a right-hand panel that streams the run as it happens: a phase
stepper (queued → gathering → exploring → synthesizing → staging writes → done), the action log
appearing as each tool returns, then verification counts and hypotheses once synthesis lands. It
polls only while the run is active and stops on completion. Close with the button, the scrim, or
Escape. **Live view** reopens it for a finished run.

A run takes ~25s on the local model — long enough that a spinner tells you nothing and a page
reload loses the thread.

### PDF export

**Export PDF** on any completed investigation produces a self-contained report: incident detail,
verification counts, every hypothesis with its evidence IDs and confidence, the full evidence
ledger, and the action log. Rejected claims and ungrounded figures are included, not filtered out —
an audit trail that quietly dropped its failures would not be one.

Also available at `/incidents/{incident}/report.pdf` (authenticated; the document contains commit
messages and diffs).

## Evaluation

```bash
php artisan eval:run
php artisan eval:run --scenario=ambiguous-tie --ablate=no-validator
php artisan eval:run --model=some-model --model=another   # same scenarios, both models
```

Results render at **`/admin/evaluation`** — per-run scores, a per-scenario breakdown showing
expected versus predicted SHA, and a bar chart comparing runs.

Five metrics, because "did it get the right answer" alone would reward a confident guesser:

- **root cause hit** — did the leading hypothesis name the real deploy
- **evidence real rate** — were the citations genuine
- **grounding rate** — were the figures traceable
- **Brier score** — did stated confidence track correctness
- **action completeness** — were all three write-ups staged

Two scenarios exist specifically to keep the eval honest:

- `ambiguous-tie` — the decoy ships in the *same minute* as the culprit and is equally on the
  request path, so timing and path scoring both tie. Only the mechanism in the diff separates them.
- `no-deploy-cause` — nothing deployed near the break. The correct answer is to implicate no
  deployment at all. A correlator that always blames its top candidate looks perfect until it meets
  this case.

Without cases like these an eval measures the correlator, not the model.

### Commits carry patches, not verdicts

Every seeded commit ships a real unified diff. Nothing in a scenario names a defect, asserts a
performance consequence, or exonerates a decoy.

That was not true at first. Culprits carried prose like *"joins orders onto payments without an
index on orders.payment_id"*, and decoys announced their own innocence: *"test-only change, not
shipped to the request path"*. An agent scoring 100% against that had demonstrated only that it
could read a label. Now it sees what `fetch_commit_diff` actually returns:

```diff
+    public function enrich(Collection $payments): Collection
+    {
+        foreach ($payments as $payment) {
+            $payment->provider_name = $payment->provider->display_name;
```

The N+1 is there to be found, but nothing says "N+1". Commit messages stay realistic
("Enrich payments with provider metadata"), and `diff_summary` is now generated file statistics in
the same format `ApiGitHubConnector` emits — so seeded commits and real ones are indistinguishable
to the agent.

The deterministic correlator is unaffected, scoring **6/7** on the rewritten scenarios, because it
reasons over timing and changed paths rather than prose. The seventh is `ambiguous-tie`, which now
resolves to a **margin of exactly 0.000** — undecidable by timing and path, exactly as intended.
Only reading the code separates those two deploys, and a test pins that tie in place so the
scenario cannot quietly stop testing what it claims to.

### Institutional memory

`search_past_incidents` lets the agent look up previous incidents on the same service and see what
each turned out to be caused by — and crucially, whether a human confirmed it. Resolved incidents
rank first, because an outcome somebody verified is worth more than ten the agent merely concluded.

### Accuracy measured against reality

Incidents can be resolved with a note and the actual cause, and every hypothesis can be marked
confirmed or refuted. That produces a second accuracy figure, kept deliberately separate from the
seeded eval: one says *capable under known conditions*, the other says *was right about a real
incident*. Averaging them would blur the distinction that matters most.

Below five verdicts the dashboard says so rather than showing a percentage, because a rate over
three judgements is noise.

### Ablations: what each guardrail is worth

`--ablate` disables a guardrail so its contribution is measured rather than assumed. All three runs
face the same seven scenarios, regenerated from scratch.

| | root cause | evidence real | grounding | Brier |
|---|---|---|---|---|
| **full** | **100%** | 100% | **100%** | **0.000** |
| `no-correlator` — model correlates unaided | 85.7% | 100% | 92.9% | 0.143 |
| `no-validator` — nothing rejected, no repair turn | 85.7% | 100% | 97.6% | 0.143 |

An earlier version of this table showed no difference at all, and the reason was the scenarios
rather than the guardrails: the diffs described their own defects, so the model could score
perfectly by reading a label. Once that was removed, both guardrails started earning their place.

The `no-validator` failure is worth stating precisely. On `no-deploy-cause` — where the correct
answer is that **no deployment is responsible** — the unguarded run blamed commit `0317177`, which
adds a markdown file and nothing else. It reached the agent through the live GitHub sync, so this
is a real commit from this repository being blamed for a latency spike. With validation on, that
claim is rejected as ungrounded and the run correctly implicates nothing.

Grounding drops too: figures that appear in no cited evidence reach the output at 92.9% and 97.6%
against 100% with both guardrails on.

**Evidence-real holds at 100% throughout**, including with the validator off. That is the honest
nuance: the ledger prevents fabricated citations upstream by fixing the valid set before generation,
so the validator is a guarantee rather than a routine corrector. It is what makes "no fabricated
citations" checkable instead of hoped-for — and the run above shows what still gets through
without it.

## Layout

```
app/Agent/        JanClient  Investigator  Correlator  EvidenceLedger  EvidenceValidator
                  ToolRegistry  Tools/*  ActionExecutor  Schemas/HypothesisSchema
app/Chaos/        ChaosProfile  ScenarioLibrary  ScenarioActivator
app/Monitoring/   IncidentDetector  SyntheticHistory
app/Connectors/   Contracts/*  {Github,Slack,Jira,Notion}/{Api,Fake}*
                  ConnectorRegistry  ConnectorSettingsRepository  ConnectorTester
app/Evaluation/   EvaluationHarness
app/Filament/     Resources/{Incidents,EvaluationRuns}  Pages/{Dashboard,ChaosControl,Connectors,Schedule}
                  Widgets/{SystemOverview,LatencyChart,EvalComparison}
app/Livewire/     InvestigationStream  (live run panel, mounted via a panel render hook)
app/Reporting/    InvestigationReport  AccuracyLedger  (verdict-based accuracy)
```

## Notes

- Postgres session timezone is pinned to UTC in `config/database.php`. The host cluster defaults to
  `Africa/Nairobi`, which silently shifted every naive timestamp by three hours on the write/read
  round trip. Correlation is entirely timestamp arithmetic, so that skew corrupted every conclusion.
- Tests run against a real Postgres database (`connector_test`), not SQLite — the correlator relies
  on `percentile_cont` and `date_trunc`, so an SQLite run would exercise different code.
