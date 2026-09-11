<?php

namespace App\Chaos;

/**
 * Seeded incidents with known root causes.
 *
 * Each scenario ships the culprit commit plus decoys deployed nearby, and each
 * commit carries a real patch rather than a description of one.
 *
 * That distinction is the whole point. An earlier version of this file gave every
 * commit a prose summary -- "joins orders onto payments without an index",
 * "test-only change, not shipped to the request path" -- which stated both the
 * defect and each decoy's innocence outright. An agent scoring well against that
 * had only demonstrated that it could read a label. Nothing here now names a
 * defect, asserts a performance consequence, or exonerates a decoy: the code says
 * what changed, and recognising the problem in it is the task being measured.
 *
 * Decoys touch plausible paths and some are genuinely close to the culprit in
 * both timing and file location, because real deploy logs look like that.
 */
class ScenarioLibrary
{
    public static function all(): array
    {
        return [
            'slow-query' => [
                'label' => 'Slow query on order history join',
                'description' => 'p95 climbs from ~30ms to ~900ms with no errors.',
                'severity' => 'sev2',
                'profile' => ['base_latency_ms' => 820, 'jitter_ms' => 220, 'error_rate' => 0.0],
                'cause' => [
                    'message' => 'Add order history to payment status response',
                    'author' => 'dana@acme.dev',
                    'changed_files' => [
                        'app/Http/Controllers/PaymentStatusController.php',
                        'app/Queries/OrderHistoryQuery.php',
                    ],
                    'additions' => 84, 'deletions' => 6,
                    'patch' => <<<'DIFF'
                    --- a/app/Queries/OrderHistoryQuery.php
                    +++ b/app/Queries/OrderHistoryQuery.php
                    @@ -14,6 +14,26 @@ class OrderHistoryQuery
                    -    public function forPayment(Payment $payment): Collection
                    -    {
                    -        return collect();
                    -    }
                    +    public function forPayment(Payment $payment): Collection
                    +    {
                    +        return DB::table('orders')
                    +            ->join('payments', 'payments.id', '=', 'orders.payment_id')
                    +            ->where('payments.customer_id', $payment->customer_id)
                    +            ->orderByDesc('orders.created_at')
                    +            ->get();
                    +    }

                    --- a/app/Http/Controllers/PaymentStatusController.php
                    +++ b/app/Http/Controllers/PaymentStatusController.php
                    @@ -28,6 +28,9 @@ public function show(Payment $payment)
                         return response()->json([
                             'id' => $payment->id,
                             'status' => $payment->status,
                    +        'history' => $this->orderHistory->forPayment($payment),
                         ]);
                    DIFF,
                ],
                'decoys' => [
                    ['offset' => -26, 'message' => 'Bump tailwind to 4.2', 'author' => 'sam@acme.dev',
                     'changed_files' => ['package.json', 'resources/css/app.css'], 'additions' => 12, 'deletions' => 12,
                     'patch' => <<<'DIFF'
                     --- a/package.json
                     +++ b/package.json
                     @@ -12,7 +12,7 @@
                          "devDependencies": {
                     -        "tailwindcss": "^4.1.3",
                     +        "tailwindcss": "^4.2.0",
                     DIFF],
                    ['offset' => -11, 'message' => 'Fix currency symbol on payment receipts', 'author' => 'kai@acme.dev',
                     'changed_files' => ['app/Http/Controllers/PaymentReceiptController.php', 'resources/views/receipt.blade.php'],
                     'additions' => 9, 'deletions' => 4,
                     'patch' => <<<'DIFF'
                     --- a/app/Http/Controllers/PaymentReceiptController.php
                     +++ b/app/Http/Controllers/PaymentReceiptController.php
                     @@ -41,7 +41,7 @@ public function show(Payment $payment)
                     -        'total' => '$' . number_format($payment->amount / 100, 2),
                     +        'total' => Money::format($payment->amount, $payment->currency),
                     DIFF],
                ],
            ],

            'n-plus-one' => [
                'label' => 'N+1 in payment provider lookup',
                'description' => 'p95 climbs past 1.4s with heavy variance.',
                'severity' => 'sev2',
                'profile' => ['base_latency_ms' => 1250, 'jitter_ms' => 480, 'error_rate' => 0.0],
                'cause' => [
                    'message' => 'Enrich payments with provider metadata',
                    'author' => 'rita@acme.dev',
                    'changed_files' => [
                        'app/Services/PaymentEnricher.php',
                        'app/Http/Resources/PaymentResource.php',
                    ],
                    'additions' => 61, 'deletions' => 3,
                    'patch' => <<<'DIFF'
                    --- a/app/Services/PaymentEnricher.php
                    +++ b/app/Services/PaymentEnricher.php
                    @@ -18,9 +18,18 @@ class PaymentEnricher
                    -    public function enrich(Collection $payments): Collection
                    -    {
                    -        return $payments;
                    -    }
                    +    public function enrich(Collection $payments): Collection
                    +    {
                    +        foreach ($payments as $payment) {
                    +            $payment->provider_name = $payment->provider->display_name;
                    +            $payment->provider_region = $payment->provider->region;
                    +        }
                    +
                    +        return $payments;
                    +    }

                    --- a/app/Http/Resources/PaymentResource.php
                    +++ b/app/Http/Resources/PaymentResource.php
                    @@ -22,6 +22,8 @@ public function toArray($request)
                    +        'provider_name' => $this->provider_name,
                    +        'provider_region' => $this->provider_region,
                    DIFF,
                ],
                'decoys' => [
                    ['offset' => -19, 'message' => 'Add CODEOWNERS', 'author' => 'sam@acme.dev',
                     'changed_files' => ['.github/CODEOWNERS'], 'additions' => 4, 'deletions' => 0,
                     'patch' => "--- /dev/null\n+++ b/.github/CODEOWNERS\n@@ -0,0 +1,4 @@\n+app/Services/  @acme/payments\n+app/Http/      @acme/payments\n"],
                    ['offset' => -7, 'message' => 'Increase worker memory limit', 'author' => 'ops@acme.dev',
                     'changed_files' => ['deploy/worker.yaml'], 'additions' => 2, 'deletions' => 2,
                     'patch' => <<<'DIFF'
                     --- a/deploy/worker.yaml
                     +++ b/deploy/worker.yaml
                     @@ -22,7 +22,7 @@ resources:
                          limits:
                     -      memory: 512Mi
                     +      memory: 1Gi
                     DIFF],
                ],
            ],

            'upstream-timeout' => [
                'label' => 'Upstream provider timeouts',
                'description' => 'Roughly a quarter of requests fail; successful ones stay slow.',
                'severity' => 'sev1',
                'profile' => ['base_latency_ms' => 340, 'jitter_ms' => 160, 'error_rate' => 0.26],
                'cause' => [
                    'message' => 'Tighten provider HTTP timeout',
                    'author' => 'ops@acme.dev',
                    'changed_files' => ['config/services.php', 'app/Clients/ProviderClient.php'],
                    'additions' => 5, 'deletions' => 5,
                    'patch' => <<<'DIFF'
                    --- a/config/services.php
                    +++ b/config/services.php
                    @@ -31,7 +31,7 @@
                         'provider' => [
                             'url' => env('PROVIDER_URL'),
                    -        'timeout' => 10,
                    +        'timeout' => 1,
                         ],

                    --- a/app/Clients/ProviderClient.php
                    +++ b/app/Clients/ProviderClient.php
                    @@ -19,7 +19,7 @@ private function request(): PendingRequest
                    -        return Http::timeout(config('services.provider.timeout'))->retry(2, 100);
                    +        return Http::timeout(config('services.provider.timeout'));
                    DIFF,
                ],
                'decoys' => [
                    ['offset' => -23, 'message' => 'Update README badges', 'author' => 'dana@acme.dev',
                     'changed_files' => ['README.md'], 'additions' => 3, 'deletions' => 3,
                     'patch' => "--- a/README.md\n+++ b/README.md\n@@ -1,3 +1,3 @@\n-[![build](https://img.shields.io/badge/build-passing-green)]()\n+[![build](https://github.com/acme/payments-api/actions/workflows/ci.yml/badge.svg)]()\n"],
                    ['offset' => -6, 'message' => 'Add retry to webhook dispatcher', 'author' => 'kai@acme.dev',
                     'changed_files' => ['app/Jobs/DispatchWebhook.php'], 'additions' => 18, 'deletions' => 2,
                     'patch' => <<<'DIFF'
                     --- a/app/Jobs/DispatchWebhook.php
                     +++ b/app/Jobs/DispatchWebhook.php
                     @@ -11,6 +11,10 @@ class DispatchWebhook implements ShouldQueue
                     +    public int $tries = 3;
                     +
                     +    public array $backoff = [10, 60];
                     +
                          public function handle(): void
                     DIFF],
                ],
            ],

            'cache-stampede' => [
                'label' => 'Cache stampede after TTL change',
                'description' => 'Sawtooth latency with occasional 500s.',
                'severity' => 'sev2',
                'profile' => ['base_latency_ms' => 640, 'jitter_ms' => 540, 'error_rate' => 0.06],
                'cause' => [
                    'message' => 'Refresh rate table more frequently',
                    'author' => 'rita@acme.dev',
                    'changed_files' => ['app/Services/RateTable.php', 'config/cache.php'],
                    'additions' => 7, 'deletions' => 7,
                    'patch' => <<<'DIFF'
                    --- a/app/Services/RateTable.php
                    +++ b/app/Services/RateTable.php
                    @@ -24,11 +24,9 @@ class RateTable
                    -        return Cache::lock('rates')->block(5, function () {
                    -            return Cache::remember('rates.table', 3600, function () {
                    -                return $this->recompute();
                    -            });
                    -        });
                    +        return Cache::remember('rates.table', 60, function () {
                    +            return $this->recompute();
                    +        });
                    DIFF,
                ],
                'decoys' => [
                    ['offset' => -17, 'message' => 'Add integration test for refunds', 'author' => 'dana@acme.dev',
                     'changed_files' => ['tests/Feature/RefundTest.php'], 'additions' => 96, 'deletions' => 0,
                     'patch' => "--- /dev/null\n+++ b/tests/Feature/RefundTest.php\n@@ -0,0 +1,96 @@\n+public function test_a_refund_reverses_the_original_charge(): void\n+{\n+    \$payment = Payment::factory()->create(['amount' => 5000]);\n+    \$this->postJson(\"/payments/{\$payment->id}/refund\")->assertOk();\n+}\n"],
                    ['offset' => -4, 'message' => 'Tune nginx keepalive', 'author' => 'ops@acme.dev',
                     'changed_files' => ['deploy/nginx.conf'], 'additions' => 3, 'deletions' => 1,
                     'patch' => <<<'DIFF'
                     --- a/deploy/nginx.conf
                     +++ b/deploy/nginx.conf
                     @@ -8,6 +8,8 @@ upstream app {
                     +    keepalive 32;
                     +    keepalive_requests 1000;
                     DIFF],
                ],
            ],

            /*
            | Deliberately hard: the decoy ships in the SAME minute as the culprit
            | and touches the same request path, so timing and file-path scoring
            | both tie. Only the code separates them.
            */
            'ambiguous-tie' => [
                'label' => 'Two request-path deploys in the same minute',
                'description' => 'p95 to ~1.1s. Two candidate deploys land seconds apart.',
                'severity' => 'sev2',
                'profile' => ['base_latency_ms' => 1050, 'jitter_ms' => 180, 'error_rate' => 0.0],
                'cause' => [
                    'message' => 'Reconcile payments against the ledger on read',
                    'author' => 'rita@acme.dev',
                    'changed_files' => ['app/Http/Controllers/PaymentStatusController.php', 'app/Services/LedgerValidator.php'],
                    'additions' => 73, 'deletions' => 4,
                    'patch' => <<<'DIFF'
                    --- a/app/Services/LedgerValidator.php
                    +++ b/app/Services/LedgerValidator.php
                    @@ -16,6 +16,24 @@ class LedgerValidator
                    +    public function reconcile(Payment $payment): bool
                    +    {
                    +        $entries = LedgerEntry::where('account_id', $payment->account_id)
                    +            ->orderBy('created_at')
                    +            ->get();
                    +
                    +        $balance = 0;
                    +        foreach ($entries as $entry) {
                    +            $balance += $entry->signedAmount();
                    +        }
                    +
                    +        return $balance === $payment->expected_balance;
                    +    }

                    --- a/app/Http/Controllers/PaymentStatusController.php
                    +++ b/app/Http/Controllers/PaymentStatusController.php
                    @@ -26,6 +26,7 @@ public function show(Payment $payment)
                    +        'reconciled' => $this->ledger->reconcile($payment),
                    DIFF,
                ],
                'decoys' => [
                    ['offset' => -2, 'message' => 'Return ISO timestamps in payment status', 'author' => 'kai@acme.dev',
                     'changed_files' => ['app/Http/Resources/PaymentResource.php', 'app/Services/TimeFormatter.php'],
                     'additions' => 21, 'deletions' => 15,
                     'patch' => <<<'DIFF'
                     --- a/app/Http/Resources/PaymentResource.php
                     +++ b/app/Http/Resources/PaymentResource.php
                     @@ -18,8 +18,8 @@ public function toArray($request)
                     -        'created_at' => $this->created_at->format('Y-m-d H:i:s'),
                     -        'settled_at' => $this->settled_at?->format('Y-m-d H:i:s'),
                     +        'created_at' => $this->created_at->toIso8601String(),
                     +        'settled_at' => $this->settled_at?->toIso8601String(),
                     DIFF],
                    ['offset' => -16, 'message' => 'Add healthcheck for redis', 'author' => 'ops@acme.dev',
                     'changed_files' => ['app/Http/Controllers/HealthController.php'], 'additions' => 11, 'deletions' => 1,
                     'patch' => <<<'DIFF'
                     --- a/app/Http/Controllers/HealthController.php
                     +++ b/app/Http/Controllers/HealthController.php
                     @@ -14,6 +14,9 @@ public function __invoke()
                     +            'redis' => $this->checkRedis(),
                     DIFF],
                ],
            ],

            /*
            | The honest negative case: nothing shipped near the break. A correlator
            | that always blames its top-ranked candidate looks perfect until here.
            */
            'no-deploy-cause' => [
                'label' => 'Degradation with no deploy behind it',
                'description' => 'Latency triples with the last deploy long past.',
                'severity' => 'sev2',
                'profile' => ['base_latency_ms' => 430, 'jitter_ms' => 120, 'error_rate' => 0.04],
                'expects_no_deploy_cause' => true,
                'deploy_offset_minutes' => -30,
                'break_offset_minutes' => -3,
                'cause' => [
                    'message' => 'Rotate TLS certificates',
                    'author' => 'ops@acme.dev',
                    'changed_files' => ['deploy/certs.yaml'],
                    'additions' => 2, 'deletions' => 2,
                    'patch' => <<<'DIFF'
                    --- a/deploy/certs.yaml
                    +++ b/deploy/certs.yaml
                    @@ -4,6 +4,6 @@ spec:
                    -  notAfter: 2026-09-12T00:00:00Z
                    +  notAfter: 2027-09-12T00:00:00Z
                    DIFF,
                ],
                'decoys' => [
                    ['offset' => -28, 'message' => 'Update contributor guide', 'author' => 'sam@acme.dev',
                     'changed_files' => ['docs/CONTRIBUTING.md'], 'additions' => 30, 'deletions' => 2,
                     'patch' => "--- a/docs/CONTRIBUTING.md\n+++ b/docs/CONTRIBUTING.md\n@@ -1,4 +1,6 @@\n+## Running the test suite\n+\n+    php artisan test\n"],
                ],
            ],

            'hard-down' => [
                'label' => 'Payments API hard down',
                'description' => 'Every request returns 503 immediately after the deploy.',
                'severity' => 'sev1',
                'profile' => ['base_latency_ms' => 60, 'jitter_ms' => 20, 'error_rate' => 1.0],
                'cause' => [
                    'message' => 'Rename PaymentGateway binding',
                    'author' => 'kai@acme.dev',
                    'changed_files' => ['app/Providers/AppServiceProvider.php', 'app/Contracts/PaymentGateway.php'],
                    'additions' => 14, 'deletions' => 11,
                    'patch' => <<<'DIFF'
                    --- a/app/Providers/AppServiceProvider.php
                    +++ b/app/Providers/AppServiceProvider.php
                    @@ -18,7 +18,7 @@ public function register(): void
                    -        $this->app->bind('payment.gateway', StripeGateway::class);
                    +        $this->app->bind(PaymentGatewayContract::class, StripeGateway::class);

                    --- a/app/Contracts/PaymentGateway.php
                    +++ b/app/Contracts/PaymentGateway.php
                    @@ -1,6 +1,6 @@
                    -interface PaymentGateway
                    +interface PaymentGatewayContract
                     {
                         public function charge(int $amount, string $currency): Charge;
                    DIFF,
                ],
                'decoys' => [
                    ['offset' => -14, 'message' => 'Add Notion export script', 'author' => 'sam@acme.dev',
                     'changed_files' => ['scripts/export-notion.php'], 'additions' => 44, 'deletions' => 0,
                     'patch' => "--- /dev/null\n+++ b/scripts/export-notion.php\n@@ -0,0 +1,44 @@\n+\$client = new NotionClient(getenv('NOTION_TOKEN'));\n+\$client->export(getenv('NOTION_PARENT_PAGE_ID'));\n"],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }
}
