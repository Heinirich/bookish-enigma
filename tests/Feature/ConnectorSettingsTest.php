<?php

namespace Tests\Feature;

use App\Connectors\ConnectorSettingsRepository;
use App\Connectors\Contracts\SlackConnector;
use App\Connectors\Jira\FakeJiraConnector;
use App\Connectors\Slack\ApiSlackConnector;
use App\Connectors\Slack\FakeSlackConnector;
use App\Models\ConnectorSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private ConnectorSettingsRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(ConnectorSettingsRepository::class);
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->settings->save('slack', 'api', ['bot_token' => 'xoxb-super-secret']);

        $raw = DB::table('connector_settings')->where('key', 'slack')->value('credentials');

        $this->assertStringNotContainsString('xoxb-super-secret', $raw,
            'A database dump must not expose the token.');
        $this->assertSame('xoxb-super-secret',
            ConnectorSetting::where('key', 'slack')->first()->credential('bot_token'));
    }

    public function test_database_values_override_the_environment(): void
    {
        config(['connectors.slack.bot_token' => 'xoxb-from-env']);

        $this->settings->save('slack', 'api', ['bot_token' => 'xoxb-from-db']);

        $this->assertSame('xoxb-from-db', $this->settings->resolve('slack')['bot_token']);
    }

    public function test_a_blank_field_falls_back_to_the_environment(): void
    {
        config(['connectors.slack.bot_token' => 'xoxb-from-env']);

        $this->settings->save('slack', 'api', ['bot_token' => '']);

        $this->assertSame('xoxb-from-env', $this->settings->resolve('slack')['bot_token']);
        $this->assertTrue($this->settings->isInheritedFromEnv('slack', 'bot_token'));
    }

    /**
     * The form never renders a stored secret back, so an untouched password field
     * submits empty. Treating that as "clear it" would destroy a working token
     * every time an unrelated field was edited.
     */
    public function test_a_blank_secret_keeps_the_stored_value(): void
    {
        $this->settings->save('jira', 'api', [
            'api_token' => 'ATATT-real-token',
            'project_key' => 'INC',
        ]);

        $this->settings->save('jira', 'api', [
            'api_token' => '',
            'project_key' => 'OPS',
        ]);

        $resolved = $this->settings->resolve('jira');

        $this->assertSame('ATATT-real-token', $resolved['api_token']);
        $this->assertSame('OPS', $resolved['project_key']);
    }

    /** A non-secret cleared in the form is genuinely cleared -- it is visible, so intent is explicit. */
    public function test_a_blank_non_secret_is_cleared(): void
    {
        config(['connectors.jira.project_key' => null]);

        $this->settings->save('jira', 'api', ['project_key' => 'INC']);
        $this->settings->save('jira', 'api', ['project_key' => '']);

        $this->assertNull($this->settings->resolve('jira')['project_key'] ?? null);
    }

    public function test_it_reports_which_required_fields_are_missing(): void
    {
        config(['connectors.jira' => ['driver' => 'api']]);

        $this->settings->save('jira', 'api', ['base_url' => 'https://x.atlassian.net']);

        $this->assertEqualsCanonicalizing(
            ['email', 'api_token', 'project_key'],
            $this->settings->missingFields('jira'),
        );
        $this->assertFalse($this->settings->isReady('jira'));
    }

    public function test_the_container_resolves_the_driver_chosen_in_the_database(): void
    {
        $this->settings->save('slack', 'api', ['bot_token' => 'xoxb-valid-looking']);
        app(ConnectorSettingsRepository::class)->hydrateConfig();

        $this->assertInstanceOf(ApiSlackConnector::class, app(SlackConnector::class));
    }

    /**
     * An api driver with missing credentials must not take an investigation down
     * mid-run; it degrades to the fake instead.
     */
    public function test_an_incomplete_api_driver_falls_back_to_the_fake(): void
    {
        config(['connectors.slack' => ['driver' => 'api', 'bot_token' => null]]);

        ConnectorSetting::create(['key' => 'slack', 'driver' => 'api', 'credentials' => []]);
        $this->settings->flush();

        $this->assertInstanceOf(FakeSlackConnector::class, app(SlackConnector::class));
    }

    public function test_unconfigured_connectors_default_to_fakes(): void
    {
        $this->assertInstanceOf(FakeSlackConnector::class, app(SlackConnector::class));
        $this->assertInstanceOf(FakeJiraConnector::class, app(\App\Connectors\Contracts\JiraConnector::class));
    }

    /** Every required credential should tell the operator where to get it. */
    public function test_every_required_field_links_to_where_the_credential_is_issued(): void
    {
        foreach (\App\Connectors\ConnectorRegistry::all() as $key => $connector) {
            foreach ($connector['fields'] as $field) {
                if (! $field['required']) {
                    continue;
                }

                $this->assertNotEmpty(
                    $field['docs_url'] ?? null,
                    "{$key}.{$field['name']} is required but has no link to where it is found.",
                );
                $this->assertStringStartsWith('https://', $field['docs_url']);
                $this->assertNotEmpty($field['docs_label'] ?? null, "{$key}.{$field['name']} has no link label.");
            }
        }
    }

    /** The Jira project list lives on the operator's own site, not a placeholder host. */
    public function test_the_jira_project_link_uses_the_configured_site_url(): void
    {
        $this->settings->save('jira', 'fake', ['base_url' => 'https://acme.atlassian.net/']);

        $page = new \App\Filament\Pages\Connectors();
        $method = new \ReflectionMethod($page, 'docsUrl');
        $method->setAccessible(true);

        $field = collect(\App\Connectors\ConnectorRegistry::get('jira')['fields'])
            ->firstWhere('name', 'project_key');

        $this->assertSame(
            'https://acme.atlassian.net/jira/projects',
            $method->invoke($page, 'jira', $field),
        );
    }

    public function test_it_rejects_an_unknown_connector(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->settings->save('pagerduty', 'api', ['token' => 'x']);
    }

    public function test_it_ignores_fields_not_declared_for_that_connector(): void
    {
        $this->settings->save('slack', 'api', [
            'bot_token' => 'xoxb-ok',
            'not_a_real_field' => 'injected',
        ]);

        $this->assertArrayNotHasKey(
            'not_a_real_field',
            ConnectorSetting::where('key', 'slack')->first()->credentials,
        );
    }
}
