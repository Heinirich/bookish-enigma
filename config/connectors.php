<?php

/*
| Every connector resolves through the container by driver. `fake` keeps the
| full investigation loop working offline, which matters when a token expires
| five minutes before a demo.
*/
return [
    'github' => [
        'driver' => env('GITHUB_DRIVER', 'fake'),
        'token' => env('GITHUB_TOKEN'),
        'repo' => env('GITHUB_REPO'),
        'api_url' => 'https://api.github.com',
    ],

    'slack' => [
        'driver' => env('SLACK_DRIVER', 'fake'),
        'bot_token' => env('SLACK_BOT_TOKEN'),
        // Verifies inbound slash commands really came from Slack.
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'api_url' => 'https://slack.com/api',
        'channel_prefix' => env('SLACK_CHANNEL_PREFIX', 'inc-'),
    ],

    'jira' => [
        'driver' => env('JIRA_DRIVER', 'fake'),
        'base_url' => env('JIRA_BASE_URL'),
        'email' => env('JIRA_EMAIL'),
        'api_token' => env('JIRA_API_TOKEN'),
        'project_key' => env('JIRA_PROJECT_KEY'),
        'issue_type' => env('JIRA_ISSUE_TYPE', 'Task'),
    ],

    'notion' => [
        'driver' => env('NOTION_DRIVER', 'fake'),
        'token' => env('NOTION_TOKEN'),
        'parent_page_id' => env('NOTION_PARENT_PAGE_ID'),
        'version' => '2022-06-28',
    ],
];
