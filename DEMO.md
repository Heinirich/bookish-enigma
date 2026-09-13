# Demo runbook

Eight minutes, four beats. The order matters: the failure comes first, because
the guardrail means nothing until the audience has seen what it prevents.

---

## Before you start

```bash
./bin/demo                 # everything up; skips what is already running
php artisan chaos reset    # /healthz healthy, clean slate
```

Open two windows:

1. **Browser** — `http://127.0.0.1:8000/admin` (already signed in)
2. **Terminal** — for the one command in Beat 1

Check the top-right of the Overview page reads **no chaos scenario active**.

---

## Beat 1 — the failure (60s)

Do not open with the product. Open with the problem.

> "I asked a local model to explain a latency spike and give me its evidence."

```bash
curl -s http://127.0.0.1:1339/v1/chat/completions -H 'Content-Type: application/json' -d '{
  "model":"Jan-v3.5-4B-Q4_K_XL",
  "messages":[{"role":"user","content":"Return a hypothesis object about a latency spike caused by deploy abc123."}],
  "response_format":{"type":"json_schema","json_schema":{"name":"h","strict":true,"schema":{
    "type":"object","properties":{"statement":{"type":"string"},"confidence":{"type":"number"},
    "evidence_ids":{"type":"array","items":{"type":"string"}}},
    "required":["statement","confidence","evidence_ids"],"additionalProperties":false}}},
  "max_tokens":300}' | python3 -m json.tool
```

It returns something like `"evidence_ids": ["latency_log_abc123_01", ...]`.

> "Every one of those IDs is invented. It sounds like a citation, it is shaped
> like a citation, and it refers to nothing. That is the problem this is built
> around — not that the model is wrong, but that you cannot tell."

**If the model returns something non-fabricated**, say so and move on — the point
still lands via the rejected-claim panel in Beat 3. Do not re-roll live.

---

## Beat 2 — an incident happens (90s)

**Chaos control → Stage** on *Cache stampede after TTL change*.

> "That did two things: it degraded the real `/healthz` endpoint this app serves,
> and it wrote a deployment record. Three deploys landed in that window. One
> caused it. Nothing in the data says which."

**Incidents** → the incident is already open.

> "Nobody filed this. A detector watching p95 against a rolling baseline opened
> it — 27× over baseline."

Point at the **Timeline**: flat line, step up, deploy triangles, changepoint cross.

---

## Beat 3 — the investigation (3 min)

Hit **Investigate**. The live panel opens and streams.

While it runs (~40s), narrate the phases:

> "Gathering is deterministic — the changepoint and deploy correlation are
> computed in PHP, not by the model. A 4B model asked to do arithmetic over
> hundreds of samples will produce confident nonsense. It gets the numbers; it
> does the judgement."

> "Now it is reading the actual diffs. The commit messages here are ordinary —
> 'Refresh rate table more frequently'. Nothing says 'this is the bug'."

When it lands, open the finding:

- **Statement** — names the deploy *and the mechanism*
- **Mechanism** — "removes a 5-second lock, shortens TTL from 3600s to 60s…"
- **Next step** — "Roll back deploy X, validate p95 returns to 38ms"
- **Evidence chips** — click one. The raw payload it came from.

> "Every number in that sentence is checkable. The chips are not decoration —
> the ledger is written *before* the model is consulted, so the set of valid
> citations is fixed in advance. An ID outside it is provably invented."

Scroll to **Verification**: claims generated, accepted, **fabricated: 0**.

---

## Beat 4 — it is measured (2 min)

**Evaluation** tab.

> "Seven seeded incidents with known causes. 7/7 root cause. Zero fabricated
> citations across 99 references."

Then the ablation — this is the strongest thing you have:

> "We ran the same seven scenarios with each guardrail switched off."

| | root cause | grounding |
|---|---|---|
| full | **100%** | **100%** |
| no correlator | 85.7% | 92.9% |
| no validator | 85.7% | 97.6% |

> "With validation off, one case fails in a specific way. On the scenario where
> the right answer is *no deploy caused this*, it blamed commit `0317177`. That
> commit adds a markdown file. It is a real commit from our repository, pulled
> in live from GitHub, and with the guardrail off the agent blamed our own demo
> script for an outage."

Then the nuance, before anyone asks:

> "Notice evidence-real stays at 100% even with the validator off. The ledger
> prevents fabricated citations upstream by fixing the valid set before the model
> is consulted. So the validator is a guarantee, not a corrector — and the run
> above shows what still gets through without it."

Finish on the write actions:

> "It drafted a Slack channel, a Jira ticket and a Notion write-up — and did not
> send them. Every outbound write waits for a human. An agent that investigates
> autonomously and publishes autonomously is a different risk profile entirely."

---

## If something breaks

| Symptom | Fix |
|---|---|
| Investigation sits on *queued* | Queue worker died: `php artisan queue:work` |
| "No models are available" / connection refused | `./bin/demo` restarts the model server on :1339 |
| `/healthz` 503 unexpectedly | A scenario is still active: `php artisan chaos reset` |
| Incident does not open after staging | `php artisan health:detect` |
| Live panel spins forever | It says "nothing is consuming the queue" — that is the worker |
| Everything looks wrong | `./bin/demo` then reload; it is idempotent |

**Fallback if the model is unreachable:** every screen still works on existing
data. Open a completed incident and walk the finding, evidence and PDF. Say the
model is local and the laptop is doing the inference — which is itself a point.

---

## Questions you will get

**"How do you know it is not just pattern-matching the commit message?"**
The scenarios were rewritten specifically to remove that. Commits carry real
diffs; nothing names the defect or exonerates a decoy. `ambiguous-tie` has two
deploys in the same minute on the same code path — the correlator scores an
exact 0.000 margin. Only reading the code separates them.

**"What if the model is wrong?"**
Then you see it is wrong, which is the point. Confidence is a field, the evidence
is one click away, and a human marks the claim confirmed or refuted — that feeds
a second accuracy number kept separate from the seeded one.

**"Why a 4B model?"**
It runs on this laptop with no API key and no data leaving the machine. The
arithmetic is deterministic, so the model only does the part it is good at.

**"Is it actually connected to those apps?"**
GitHub is live — it pulls real deploys and commits from our repo. Show
`php artisan connectors test github`.
