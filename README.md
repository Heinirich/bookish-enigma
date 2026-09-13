# Connector

**An incident response agent that cannot assert a cause without evidence you can check.**

Built for the Multi-App AI Agent Hackathon. Runs on a local 4B model — no API key, no data
leaving the machine.

---

## The problem, shown rather than described

Ask a language model to explain an outage and cite its evidence. Here is what one actually returned,
unprompted, the first time we tried:

```json
{
  "statement": "A latency spike was observed during the deployment of 'abc123'.",
  "evidence_ids": ["LOG-DEPLOY-ABC123-001", "METRIC-LATENCY-002", "TRACE-SPARK-003"]
}
```

Every one of those identifiers is invented. They are shaped like citations, formatted like
citations, and refer to nothing at all.

That is the problem this project is built around. Not that the model is sometimes wrong — that when
it is wrong, **nothing in the output tells you.** An incident report you cannot audit is worse than
no report, because it carries the authority of one.

---

## 01 · Project overview

An alert fires: *"payment API latency spike."* Someone spends forty minutes pulling up dashboards,
scrolling deploy history and guessing which of the last six commits did it. They write it up badly,
and the next person on call starts over.

**Connector turns that vague alert into a documented investigation.** It measures what happened,
correlates it against what shipped, reads the actual diffs, and reports a cause with a mechanism and
a next step — then drafts the Slack channel, the Jira ticket and the Notion write-up and waits for a
human before sending any of them.

The design commitment is that **the agent never asserts a cause in prose.** Every conclusion is a
structured object:

| Field | |
|---|---|
| `statement` | the claim |
| `mechanism` | what in the changed code produces this symptom |
| `remediation` | the next action a responder should take |
| `stance` | `cause` or `ruled_out` |
| `probability_this_explains_the_incident` | 0–1 |
| `evidence_ids` | the evidence it rests on |

Those evidence IDs are validated against a ledger written **before the model is consulted**, so the
set of legitimate citations is fixed in advance. An identifier outside that set is *provably*
invented rather than merely unverified. That is the whole idea, and everything else serves it.

The system also monitors itself. A `/healthz` endpoint with an injectable chaos profile is polled
continuously; staging a degradation also writes a deployment record, so every demo run is a scored
evaluation case with known ground truth rather than an anecdote.

---

## 02 · External apps used

| App | What the agent does with it |
|---|---|
| **GitHub** | Pulls deployments, commits and real diffs for the incident window. This is the evidence root-cause correlation depends on. |
| **Slack** | Opens the incident channel and posts the summary. Also accepts `/investigate` as an inbound slash command. |
| **Jira** | Files the incident ticket in Atlassian Document Format, linked to the suspect commit. |
| **Notion** | Publishes the full investigation write-up including the evidence ledger. |

Each connector has a real API driver and a Fake driver behind the same contract
(`app/Connectors/`). The fakes keep the whole loop — and every evaluation run — working offline,
which matters when a token expires minutes before a demo. A driver set to `api` with incomplete
credentials falls back to its fake rather than failing mid-investigation, and the UI is explicit
about which is in use.

**Slack is two-way.** `/investigate the payment API feels slow` opens an incident from any channel
and returns the findings there. It is the only publicly reachable write path, so it verifies Slack's
HMAC request signature with replay rejection, and refuses every request when no signing secret is
configured.

### Credentials

Configured at `/admin/connectors`, **encrypted at rest in the database** rather than sitting in
`.env`. A database dump alone does not expose them. Secrets are never rendered back to the browser —
a saved token shows as a masked placeholder, and submitting it blank keeps the stored value, so
editing one field cannot silently wipe another.

Every field carries a link to the exact page that issues that credential. Verify without creating
anything:

```bash
php artisan connectors status   # drivers, missing fields, whether each value came from db or env
php artisan connectors test all # read-only identity checks against the live APIs
```

### Every outbound write waits for a human

Slack channels, Jira tickets and Notion pages are **staged, not sent.** They appear on the incident
page as pending actions with an explicit approve or reject. An agent that investigates autonomously
and publishes autonomously is a different risk profile, and not one worth shipping.

---

## 03 · Setup instructions

**Requires** PHP 8.4, PostgreSQL, Redis, Node 20+, and [Jan](https://jan.ai) with the
`Jan-v3.5-4B-Q4_K_XL` model downloaded.

```bash
git clone https://github.com/Heinirich/shiny-octo-pancake.git connector
cd connector

composer install
npm install && npm run build

cp .env.example .env
php artisan key:generate

createdb connector
php artisan migrate --seed
```

Then bring the stack up:

```bash
./bin/demo
```

That starts Redis, the local model server, the web server, the queue worker and the scheduler,
skipping whatever is already running. All five must be alive for an investigation to complete — the
script reports plainly when one fails rather than letting it look fine.

Sign in at **`http://127.0.0.1:8000/admin`** — `heinrich@quickorganics.com` / `password`.

### See the whole loop in ninety seconds

```bash
php artisan demo:seed cache-stampede --fresh   # stage an incident with a known cause
php artisan health:detect                      # detector opens it, queues the investigation
```

Or press **Stage** on any scenario under *Chaos control*, then **Investigate** on the incident that
appears. A full walkthrough is in [DEMO.md](DEMO.md).

### If something misbehaves

| Symptom | Cause |
|---|---|
| Investigation stuck on *queued* | Queue worker died — `php artisan queue:work` |
| `/healthz` returning 503 | A chaos scenario is still active — `php artisan chaos reset` |
| "No models are available" | Model server down — `./bin/demo` restarts it |
| Anything else | `./bin/demo` is idempotent; run it again |

---

## 04 · Reliability testing

Three independent layers, because *"the demo worked once"* is not evidence.

### A 52-test suite

```bash
php artisan test
```

Run against **real PostgreSQL, not SQLite** — the correlator depends on `percentile_cont` and
`date_trunc` window functions, so an SQLite run would exercise different code and prove nothing.

Coverage is aimed at the things that would silently void the project's central claim: the
validator's adversarial cases (fabricated identifiers, invented figures written with unit suffixes,
substring coincidences), correlator ranking across every scenario, Slack signature forgery and
replay, credential precedence, and the scheduler's rate limits.

### An evaluation harness

```bash
php artisan eval:run
```

Scores the agent against **seven seeded incidents with known causes**. Five metrics, because *"did
it get the right answer"* alone would reward a confident guesser:

| Metric | |
|---|---|
| **root cause hit** | did the leading hypothesis name the real deploy |
| **evidence real** | were the citations genuine |
| **grounding** | were the figures traceable to the cited evidence |
| **Brier score** | did stated confidence track correctness |
| **action completeness** | were all three write-ups staged |

**Current result: 7/7 root cause · 100% evidence-real · 0 fabricated citations across 99
references.**

Results render at `/admin/evaluation` with a per-scenario breakdown showing expected versus
predicted SHA.

### Scenarios designed to make that number honest

Commits carry **real unified diffs**, not descriptions of them. Nothing in a scenario names a
defect, asserts a performance consequence, or exonerates a decoy:

```diff
+    public function enrich(Collection $payments): Collection
+    {
+        foreach ($payments as $payment) {
+            $payment->provider_name = $payment->provider->display_name;
```

The N+1 is there to be found, but nothing says "N+1". An earlier version gave every commit a prose
summary — *"joins orders onto payments without an index"* — and decoys announced their own
innocence — *"test-only change, not shipped to the request path."* An agent scoring perfectly
against that had demonstrated only that it could read a label.

Two scenarios exist specifically to prevent a flattering result:

- **`ambiguous-tie`** ships a decoy in the *same minute* as the culprit, on the same code path. The
  correlator scores an exact **0.000** margin — a test pins that tie in place — so only reading the
  code can separate them.
- **`no-deploy-cause`** has nothing shipped near the break. The correct answer is to implicate **no
  deployment at all**. A correlator that always blames its top candidate looks perfect until here.

### Ablations: what each guardrail is actually worth

```bash
php artisan eval:run --ablate=no-correlator --ablate=no-validator
```

| | root cause | grounding | Brier |
|---|---|---|---|
| **full** | **100%** | **100%** | **0.000** |
| `no-correlator` — model correlates unaided | 85.7% | 92.9% | 0.143 |
| `no-validator` — nothing rejected, no repair turn | 85.7% | 97.6% | 0.143 |

With validation off, on the scenario where no deploy is responsible, the agent blamed commit
`0317177` — **a commit that adds a markdown file and nothing else.** That is a real commit from this
repository, pulled in live through the GitHub connector. With the guardrail on, the claim is
rejected as ungrounded and the run correctly implicates nothing.

**Evidence-real holds at 100% throughout, including with the validator disabled.** That is the
honest nuance rather than a stronger claim: the ledger prevents fabricated citations *upstream* by
fixing the valid set before generation, so the validator is a guarantee rather than a routine
corrector. It is what makes "no fabricated citations" checkable instead of hoped-for — and the run
above shows what still reaches the output without it.

### Accuracy against reality, kept separate

Seeded scenarios measure capability under conditions we planted. Incidents can also be **resolved
with a human verdict** on each hypothesis — confirmed or refuted — producing a second accuracy
figure kept deliberately apart on the dashboard. *"Right about a real incident"* and *"right about
one we planted"* are different claims, and averaging them would blur the distinction that matters
most. Below five verdicts the dashboard says so rather than showing a percentage, because a rate
over three judgements is noise.

---

## 05 · Demo video

> **📹 Two-minute demo:** _link to be added_

<!-- Paste the link above once recorded. Script and timings are in VIDEO.md. -->

---

## How it works

```
chaos scenario  →  /healthz degrades  →  pinger records samples  →  detector opens an incident
                                                                              ↓
        Slack + Jira + Notion  ←  validated hypotheses  ←  agent investigates
             (staged for approval)
```

Four phases, in deliberate order:

| Phase | |
|---|---|
| **gather** | Deterministic. GitHub is synced, then PHP computes the latency changepoint and scores every deploy by timing and changed-path relevance. Evidence exists before the model is consulted — and exists even if the model fails. |
| **explore** | The model pulls further facts through read-only tools: metrics, deployments, commit diffs, and past incidents. Every call is logged; everything returned joins the ledger. |
| **synthesize** | Hypotheses under a strict JSON schema, then validated against the ledger. |
| **act** | Slack, Jira and Notion writes — staged for approval, never automatic. |

### Deterministic correlation, model narration

A 4B model asked to locate a changepoint across hundreds of samples and match it to a deploy will
confabulate plausible arithmetic. So `App\Agent\Correlator` establishes **what happened and when**
in PHP, and the model is left with the genuinely interpretive part: which candidate explains it, and
how much confidence that deserves.

This is what makes a small local model viable. The correlator alone scores 6/7 deterministically;
the seventh is `ambiguous-tie`, which it cannot win by construction.

### The evidence ledger

Nothing reaches the model that has not first been written to `App\Agent\EvidenceLedger` and given a
public handle (`EV-1`, `EV-2`, …). `EvidenceValidator` then applies two gates:

- **Existence** — a cited ID must belong to this investigation. Failing this is fatal: one repair
  turn naming the bad IDs, then the claim is stored as `rejected_unsupported` and displayed as
  discarded. Rejected claims are kept on purpose; a board that quietly cleaned itself would defeat
  the point.
- **Grounding** — figures in the statement must be traceable to the cited payload, with a 5%
  tolerance so sensible rounding passes. A statement whose figures are *all* invented is rejected.

### Structure beats instruction

Two findings worth recording, both learned by failing first.

Asked in the prompt for a mechanism, the model kept emitting *"Deploy abc1234 caused the latency
spike."* Strengthening the prompt changed nothing across two attempts. Making `mechanism` a
**grammar-enforced schema field** fixed it immediately — a field cannot be skipped the way an
instruction can.

The same applied to confidence. Named `confidence`, the model read it as certainty in its own
reasoning and put **0.99 on a ruled-out candidate**, inverting the number and corrupting the Brier
score. The description said otherwise; the field name won. Renaming it to
`probability_this_explains_the_incident` fixed it on the next run.

---

## Using it

| | |
|---|---|
| **Chaos control** | Stage a degradation. Writes the culprit deploy plus decoys and backfills ~35 minutes of samples, so ground truth exists for free. |
| **New investigation** | Type a plain-language prompt — *"investigate the payment API latency spike"*. The prompt reaches the agent untouched, but the incident carries the same measured signal the detector would have attached. |
| **Live view** | A right-hand panel streaming the run: phase stepper, action log as each tool returns, then verification counts and hypotheses. Polls only while active. |
| **Export PDF** | A self-contained report — findings, evidence ledger, action log. Rejected claims and ungrounded figures included, not filtered; an audit trail that dropped its failures would not be one. |
| **Schedule** | Poll interval, detection interval, auto-investigate, an hourly ceiling on automated runs, and quiet hours — beside the *observed* rate, so you can see the cap swallowing work. |
| **Services** | Point it at your own health endpoints, not just its own. |

---

## Layout

```
app/Agent/        JanClient  Investigator  Correlator  EvidenceLedger  EvidenceValidator
                  ToolRegistry  Tools/*  ActionExecutor  Schemas/HypothesisSchema
app/Chaos/        ChaosProfile  ScenarioLibrary  ScenarioActivator
app/Monitoring/   IncidentDetector  SyntheticHistory  ManualIncidentOpener
app/Connectors/   Contracts/*  {Github,Slack,Jira,Notion}/{Api,Fake}*
                  ConnectorRegistry  ConnectorSettingsRepository  ConnectorTester
app/Evaluation/   EvaluationHarness
app/Reporting/    InvestigationReport  AccuracyLedger
app/Filament/     Resources/{Incidents,EvaluationRuns,MonitoredEndpoints}
                  Pages/{Dashboard,ChaosControl,Connectors,Schedule}
app/Livewire/     InvestigationStream
bin/demo          brings the whole stack up
```

---

## Notes and limitations

- **Inference is local.** Jan's own API server did not reliably expose the loaded model, so
  `bin/demo` starts the `llama-server` Jan ships directly on `:1339` and the app points at it.
- **Metrics come from the app's own `/healthz`**, not Grafana or Datadog. The submission named those;
  self-monitoring was chosen instead so the demo and the evaluation share one mechanism and every run
  has ground truth.
- **Postgres session timezone is pinned to UTC** in `config/database.php`. The development machine's
  cluster defaulted to `Africa/Nairobi`, which silently shifted every naive timestamp by three hours
  on the write/read round trip. Correlation is entirely timestamp arithmetic, so that skew corrupted
  every conclusion until it was found.
- **A 4B model is the constraint that shaped the architecture.** Deterministic correlation and
  schema-enforced fields exist because instruction-following degrades at 14k context. `config/agent.php`
  can point synthesis at a larger model without touching any other code.
