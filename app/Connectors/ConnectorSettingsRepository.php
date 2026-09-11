<?php

namespace App\Connectors;

use App\Models\ConnectorSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves connector configuration from the database, falling back to config/env.
 *
 * Credentials belong in the database so they can be changed from the UI without
 * editing a file and restarting. The env values remain valid as defaults, which
 * keeps a fresh checkout working and gives a deployment somewhere to seed from.
 *
 * Precedence, per field: database value → config/env → null.
 */
class ConnectorSettingsRepository
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $memo = null;

    /**
     * Push resolved settings into the runtime config.
     *
     * The API drivers read `config('connectors.*')` directly, so hydrating once
     * per request keeps them unchanged and leaves a single place where database
     * values override env — rather than scattering lookups through four clients.
     */
    public function hydrateConfig(): void
    {
        foreach (ConnectorRegistry::keys() as $key) {
            config(["connectors.{$key}" => $this->resolve($key)]);
        }
    }

    /** @return array<string,mixed> the effective configuration for one connector */
    public function resolve(string $key): array
    {
        $config = config("connectors.{$key}", []);
        $setting = $this->stored()[$key] ?? null;

        if ($setting === null) {
            return $config;
        }

        $config['driver'] = $setting->driver;

        foreach (($setting->credentials ?? []) as $field => $value) {
            // Blank means "not set here", so the env default still applies.
            if ($value !== null && $value !== '') {
                $config[$field] = $value;
            }
        }

        return $config;
    }

    public function save(string $key, string $driver, array $credentials): ConnectorSetting
    {
        if (! ConnectorRegistry::exists($key)) {
            throw new \InvalidArgumentException("Unknown connector [{$key}].");
        }

        $existing = $this->stored()[$key] ?? null;
        $merged = $existing?->credentials ?? [];

        foreach ($credentials as $field => $value) {
            if (! in_array($field, ConnectorRegistry::fieldNames($key), true)) {
                continue;
            }

            // A blank secret means "leave it alone" -- the form never renders the
            // stored value back, so an untouched password field submits empty and
            // must not wipe a working token.
            if ($value === null || $value === '') {
                if (in_array($field, ConnectorRegistry::secretFields($key), true)) {
                    continue;
                }

                unset($merged[$field]);

                continue;
            }

            $merged[$field] = $value;
        }

        $setting = ConnectorSetting::updateOrCreate(
            ['key' => $key],
            ['driver' => $driver, 'credentials' => $merged],
        );

        $this->flush();

        return $setting;
    }

    public function forget(string $key): void
    {
        ConnectorSetting::where('key', $key)->delete();
        $this->flush();
    }

    public function setting(string $key): ?ConnectorSetting
    {
        return $this->stored()[$key] ?? null;
    }

    /** Which required fields are still missing once env fallbacks are applied. */
    public function missingFields(string $key): array
    {
        $resolved = $this->resolve($key);

        return array_values(array_filter(
            ConnectorRegistry::requiredFields($key),
            fn (string $field) => blank($resolved[$field] ?? null),
        ));
    }

    public function isReady(string $key): bool
    {
        return $this->missingFields($key) === [];
    }

    /** True when a field's value comes from env rather than the database. */
    public function isInheritedFromEnv(string $key, string $field): bool
    {
        $stored = $this->stored()[$key]?->credentials[$field] ?? null;

        return blank($stored) && filled(config("connectors.{$key}.{$field}"));
    }

    public function flush(): void
    {
        $this->memo = null;
    }

    /** @return array<string,ConnectorSetting> */
    private function stored(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        // The table is absent during the first migrate and while building a
        // config cache, so a missing table has to mean "env only", not a crash.
        try {
            if (! Schema::hasTable('connector_settings')) {
                return $this->memo = [];
            }

            return $this->memo = ConnectorSetting::all()->keyBy('key')->all();
        } catch (\Throwable) {
            return $this->memo = [];
        }
    }
}
