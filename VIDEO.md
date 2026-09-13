# Two-minute demo script

The brief caps it at two minutes, and judging gives 10% to demo clarity. Record
screen + voice in one take; retakes are cheaper than editing.

**Have ready before recording:** `./bin/demo` run, `php artisan chaos reset`,
Overview tab open, a terminal beside it.

---

### 0:00–0:20 — the problem, shown not described

*Terminal. Run the fabrication probe (command in DEMO.md).*

> "I asked a local model to explain a latency spike and cite its evidence. It
> returned three citation IDs. All three are invented — they refer to nothing.
> That's the problem: not that the model is wrong, but that you can't tell."

### 0:20–0:40 — an incident, unprompted

*Chaos control → Stage "Cache stampede". Switch to Incidents.*

> "That degraded a real endpoint this app serves, and shipped three deploys.
> Nobody filed this incident — a detector opened it, p95 27× over baseline."

*Point at the timeline: flat, step up, deploy markers, changepoint.*

### 0:40–1:20 — the investigation

*Hit Investigate. Panel streams.*

> "Correlation is computed in PHP, not by the model — a 4B model doing arithmetic
> over hundreds of samples produces confident nonsense. It gets the numbers, then
> reads the actual diffs. The commit messages are ordinary; nothing says 'bug'."

*Finding lands. Point at each part.*

> "Cause, mechanism — it found the removed lock and the TTL drop — and a next
> step. Every figure is clickable back to the evidence it came from."

*Click one evidence chip.*

> "The ledger is written before the model is consulted, so the valid citation set
> is fixed in advance. An ID outside it is provably invented."

### 1:20–1:45 — it connects, and it waits

*Scroll to write actions.*

> "It drafted a Slack channel, a Jira ticket and a Notion write-up — and sent
> none of them. Every outbound write waits for a human. GitHub is read live: those
> deploys and diffs come from our real repository."

### 1:45–2:00 — how we know it works

*Evaluation tab.*

> "Seven seeded incidents with known causes: 7 out of 7, zero fabricated
> citations across 99 references. With the guardrail switched off it blamed a
> commit that only adds a markdown file. That's the difference the validation makes."

---

**Cut ruthlessly.** If you overrun, drop 1:20–1:45 to one sentence — the
evaluation ending is worth more than the integration list, because the brief asks
"show how you know it works" and that's 25% of the score.
