<?php

namespace App\Providers;

use App\Connectors\ConnectorSettingsRepository;
use App\Connectors\Contracts\GitHubConnector;
use App\Connectors\Contracts\JiraConnector;
use App\Connectors\Contracts\NotionConnector;
use App\Connectors\Contracts\SlackConnector;
use App\Connectors\Github\ApiGitHubConnector;
use App\Connectors\Github\FakeGitHubConnector;
use App\Connectors\Jira\ApiJiraConnector;
use App\Connectors\Jira\FakeJiraConnector;
use App\Connectors\Notion\ApiNotionConnector;
use App\Connectors\Notion\FakeNotionConnector;
use App\Connectors\Slack\ApiSlackConnector;
use App\Connectors\Slack\FakeSlackConnector;
use Illuminate\Support\ServiceProvider;

class ConnectorServiceProvider extends ServiceProvider
{
    /** @var array<class-string,array{0:string,1:class-string,2:class-string}> */
    private const BINDINGS = [
        GitHubConnector::class => ['github', ApiGitHubConnector::class, FakeGitHubConnector::class],
        SlackConnector::class => ['slack', ApiSlackConnector::class, FakeSlackConnector::class],
        JiraConnector::class => ['jira', ApiJiraConnector::class, FakeJiraConnector::class],
        NotionConnector::class => ['notion', ApiNotionConnector::class, FakeNotionConnector::class],
    ];

    public function register(): void
    {
        $this->app->singleton(ConnectorSettingsRepository::class);

        foreach (self::BINDINGS as $contract => [$key, $api, $fake]) {
            $this->app->singleton($contract, function ($app) use ($key, $api, $fake) {
                $settings = $app->make(ConnectorSettingsRepository::class);
                $driver = $settings->resolve($key)['driver'] ?? 'fake';

                if ($driver !== 'api') {
                    return $app->make($fake);
                }

                $implementation = $app->make($api);

                // A driver set to `api` with incomplete credentials would fail
                // mid-investigation. Falling back keeps the run alive, and the
                // settings page shows which fields are missing.
                return $implementation->isConfigured() ? $implementation : $app->make($fake);
            });
        }
    }

    public function boot(ConnectorSettingsRepository $settings): void
    {
        /*
        | Database-backed credentials override the env defaults for the rest of
        | the request. Done once here rather than inside each client, so there is
        | a single point where precedence is decided -- and the API drivers keep
        | reading plain config() as they always did.
        */
        $settings->hydrateConfig();
    }
}
