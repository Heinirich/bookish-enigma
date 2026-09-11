<?php

namespace App\Filament\Pages;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorSettingsRepository;
use App\Connectors\ConnectorTester;
use App\Models\ConnectorSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Credentials live in the database, not in .env, so a connector can be wired up
 * (or switched to its fake) without editing a file or restarting anything.
 *
 * Secrets are never rendered back to the browser. A stored token shows as a
 * masked placeholder and an empty submission leaves it untouched, so saving an
 * unrelated field cannot silently wipe a working credential.
 */
class Connectors extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Connectors';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.connectors';

    public ?array $data = [];

    public function getTitle(): string
    {
        return 'Connectors';
    }

    public function getSubheading(): ?string
    {
        return 'Stored encrypted in the database. Values left blank fall back to the environment.';
    }

    public function mount(): void
    {
        $this->form->fill($this->currentState());
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components(
            collect(ConnectorRegistry::all())
                ->map(fn (array $connector, string $key) => $this->section($key, $connector))
                ->values()
                ->all(),
        );
    }

    private function section(string $key, array $connector): Section
    {
        return Section::make($connector['label'])
            ->description($connector['description'])
            ->icon($connector['icon'])
            ->collapsible()
            ->collapsed(fn () => ! $this->stored($key)?->isApi())
            ->headerActions([
                Action::make("test_{$key}")
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->color('gray')
                    ->action(fn () => $this->testConnector($key)),
            ])
            ->schema([
                Select::make("{$key}.driver")
                    ->label('Driver')
                    ->options([
                        'fake' => 'Fake — records calls locally, touches nothing',
                        'api' => 'API — writes to the real workspace',
                    ])
                    ->default('fake')
                    ->native(false)
                    ->live()
                    ->helperText('An API driver with incomplete credentials falls back to the fake rather than failing mid-investigation.'),

                ...array_map(
                    fn (array $field) => $this->field($key, $field),
                    $connector['fields'],
                ),
            ])
            ->columns(1);
    }

    /**
     * Every hint is a closure rather than a computed value.
     *
     * The schema is built once, but whether a secret is stored changes the moment
     * one is saved. Resolving eagerly left a just-saved token still advertising an
     * empty placeholder, which reads as "nothing was saved".
     */
    private function field(string $key, array $field): TextInput
    {
        $name = $field['name'];
        $hasStored = fn () => filled($this->stored($key)?->credential($name));
        $fromEnv = fn () => app(ConnectorSettingsRepository::class)->isInheritedFromEnv($key, $name);

        $input = TextInput::make("{$key}.{$name}")
            ->label($field['label'])
            ->placeholder($field['placeholder'] ?? null)
            ->helperText($field['help'] ?? null)
            // Only some fields have somewhere meaningful to point at; hintAction
            // rejects null, so it is applied conditionally rather than always.
            ->when(
                $this->docsAction($key, $field),
                fn (TextInput $input, Action $action) => $input->hintAction($action),
            )
            // Required only when this connector is actually set to hit the API,
            // and only when no value already exists from a save or from env.
            ->required(fn (callable $get) => $field['required']
                && $get("{$key}.driver") === 'api'
                && ! $hasStored()
                && ! $fromEnv());

        if ($field['secret']) {
            return $input
                ->password()
                ->revealable()
                ->autocomplete('new-password')
                ->placeholder(fn () => $hasStored()
                    ? '•••••••••••• stored'
                    : ($field['placeholder'] ?? null))
                ->helperText(fn () => match (true) {
                    $hasStored() => 'A value is saved. Type a new one to replace it; leave blank to keep it.',
                    $fromEnv() => 'Currently inherited from the environment.',
                    default => $field['help'] ?? null,
                });
        }

        return $input->helperText(fn () => $fromEnv()
            ? 'Inherited from the environment: '.config("connectors.{$key}.{$name}")
            : ($field['help'] ?? null));
    }

    private function stored(string $key): ?ConnectorSetting
    {
        return app(ConnectorSettingsRepository::class)->setting($key);
    }

    /**
     * The "where do I get this?" link beside a field.
     *
     * Points at the exact page the credential is issued on rather than a product
     * homepage — hunting for the token screen is most of the friction in wiring
     * up a connector, and a generic docs link does not remove it.
     */
    private function docsAction(string $key, array $field): ?Action
    {
        if (blank($field['docs_url'] ?? null)) {
            return null;
        }

        $tooltip = $field['where'] ?? $field['docs_label'];

        return Action::make("docs_{$key}_{$field['name']}")
            ->label($field['docs_label'] ?? 'Where do I find this?')
            ->icon('heroicon-m-question-mark-circle')
            ->link()
            ->color('gray')
            ->tooltip($tooltip)
            ->url(fn () => $this->docsUrl($key, $field), shouldOpenInNewTab: true);
    }

    /**
     * Some links are only useful once another field is known — a Jira project
     * list lives on the operator's own site, so it is built from the site URL
     * they have already entered rather than pointing at a placeholder host.
     */
    private function docsUrl(string $key, array $field): string
    {
        $suffix = $field['docs_from_base_url'] ?? null;

        if ($suffix !== null) {
            $baseUrl = app(ConnectorSettingsRepository::class)->resolve($key)['base_url'] ?? null;

            if (filled($baseUrl)) {
                return rtrim($baseUrl, '/').$suffix;
            }
        }

        return $field['docs_url'];
    }

    /** Secrets are deliberately omitted so they are never sent to the browser. */
    private function currentState(): array
    {
        $settings = app(ConnectorSettingsRepository::class);
        $state = [];

        foreach (ConnectorRegistry::all() as $key => $connector) {
            $resolved = $settings->resolve($key);
            $state[$key]['driver'] = $resolved['driver'] ?? 'fake';

            foreach ($connector['fields'] as $field) {
                $state[$key][$field['name']] = $field['secret']
                    ? null
                    : ($resolved[$field['name']] ?? null);
            }
        }

        return $state;
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(ConnectorSettingsRepository::class);

        foreach (ConnectorRegistry::keys() as $key) {
            $section = $data[$key] ?? [];
            $driver = $section['driver'] ?? 'fake';
            unset($section['driver']);

            $settings->save($key, $driver, $section);
        }

        // Re-fill so newly stored secrets switch to their masked placeholder.
        $this->form->fill($this->currentState());

        Notification::make()
            ->title('Connector settings saved')
            ->body('Encrypted and applied immediately — no restart needed.')
            ->success()
            ->send();
    }

    public function testConnector(string $key): void
    {
        // Test what is saved, not what is typed but unsaved, or the result lies.
        $result = app(ConnectorTester::class)->test($key);

        Notification::make()
            ->title(ConnectorRegistry::get($key)['label'].($result['ok'] ? ' connected' : ' failed'))
            ->body($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            ->persistent()
            ->send();
    }

    public function statusFor(string $key): array
    {
        $settings = app(ConnectorSettingsRepository::class);
        $stored = $settings->setting($key);
        $driver = $settings->resolve($key)['driver'] ?? 'fake';

        return [
            'driver' => $driver,
            'ready' => $settings->isReady($key),
            'missing' => $settings->missingFields($key),
            'last_status' => $stored?->last_test_status,
            'last_message' => $stored?->last_test_message,
            'last_tested_at' => $stored?->last_tested_at,
        ];
    }

    public function connectorList(): array
    {
        return ConnectorRegistry::all();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save connectors')->submit('save'),
        ];
    }
}
