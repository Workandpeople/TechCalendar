<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\ApplicationSettingAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ApplicationSettings
{
    public const CACHE_KEY = 'application_settings.active_values';

    /**
     * @var array<string, mixed>
     */
    private static array $initialConfigValues = [];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return collect(config('application_settings.definitions', []))
            ->reject(fn ($definition, $key) => config('crm.v2_enabled') && str_starts_with($key, 'services.coffrac.'))
            ->all();
    }

    public function missingCount(): int
    {
        $values = $this->databaseValues();

        return collect($this->definitions())->filter(function ($definition, $key) use ($values) {
            $value = collect([
                $values[$key] ?? null,
                isset($definition['env']) ? env($definition['env']) : null,
                isset($definition['config']) ? $this->initialConfigValue($definition['config']) : null,
                $definition['fallback'] ?? null,
            ])->first(fn ($value) => $value !== null && $value !== '');

            return blank($value);
        })->count();
    }

    public function applyToConfig(?string $configPrefix = null): void
    {
        $values = $this->databaseValues();
        foreach ($this->definitions() as $key => $definition) {
            $configKey = $definition['config'] ?? null;

            if (! $configKey || ($configPrefix !== null && ! str_starts_with($configKey, $configPrefix))) {
                continue;
            }

            $this->rememberInitialConfigValue($configKey);

            $value = $this->resolveValue($definition, $values[$key] ?? null);

            config([$configKey => $value]);
        }
    }

    public function get(string $key): mixed
    {
        $definition = $this->definitions()[$key] ?? null;

        if (! $definition) {
            return null;
        }

        return $this->resolveValue($definition, $this->databaseValues()[$key] ?? null);
    }

    private function resolveValue(array $definition, mixed $databaseValue): mixed
    {
        if ($databaseValue !== null && $databaseValue !== '') {
            return $this->castValue($databaseValue, $definition['type'] ?? 'string');
        }

        $envValue = isset($definition['env']) ? env($definition['env']) : null;

        if ($envValue !== null && $envValue !== '') {
            return $this->castValue($envValue, $definition['type'] ?? 'string');
        }

        $configKey = $definition['config'] ?? null;

        if ($configKey) {
            $configValue = $this->initialConfigValue($configKey);

            if ($configValue !== null && $configValue !== '') {
                return $this->castValue($configValue, $definition['type'] ?? 'string');
            }
        }

        return $definition['fallback'] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function formRows(): array
    {
        try {
            $stored = Schema::hasTable('application_settings')
                ? ApplicationSetting::query()->get()->keyBy('key') : collect();
        } catch (Throwable) {
            $stored = collect();
        }

        return collect($this->definitions())
            ->map(function (array $definition, string $key) use ($stored): array {
                $setting = $stored->get($key);
                $hasDatabaseValue = $setting && $setting->is_active && filled($setting->value);
                $hasEnvValue = $this->hasEnvironmentValue($definition);
                $effectiveSource = $hasDatabaseValue ? 'bdd' : ($hasEnvValue ? 'env' : 'fallback');
                $effectiveValue = $this->resolveValue($definition, $hasDatabaseValue ? $setting->value : null);

                return [
                    'key' => $key,
                    'group' => $definition['group'],
                    'label' => $definition['label'],
                    'type' => $definition['type'] ?? 'string',
                    'env_key' => $definition['env'] ?? null,
                    'description' => $definition['description'] ?? null,
                    'options' => $definition['options'] ?? [],
                    'is_secret' => (bool) ($definition['secret'] ?? false),
                    'value' => ($definition['secret'] ?? false) ? null : $effectiveValue,
                    'has_database_value' => $hasDatabaseValue,
                    'has_env_value' => $hasEnvValue,
                    'is_missing' => blank($effectiveValue),
                    'source' => $effectiveSource,
                    'updated_at' => $setting?->updated_at,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values, int $userId): void
    {
        DB::transaction(function () use ($values, $userId): void {
            foreach ($this->definitions() as $key => $definition) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }
                $incomingValue = $values[$key];
                $incomingValue = is_string($incomingValue) ? trim($incomingValue) : $incomingValue;
                $setting = $this->setting($key);
                $previousHadValue = $setting && filled($setting->value);

                if (($definition['secret'] ?? false) && blank($incomingValue)) {
                    continue;
                }

                $setting = ApplicationSetting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'group' => $definition['group'],
                        'label' => $definition['label'],
                        'type' => $definition['type'] ?? 'string',
                        'value' => $incomingValue,
                        'is_secret' => (bool) ($definition['secret'] ?? false),
                        'is_active' => filled($incomingValue),
                        'description' => $definition['description'] ?? null,
                        'validation_rules' => $definition['rules'] ?? [],
                        'updated_by' => $userId,
                    ],
                );

                ApplicationSettingAudit::query()->create([
                    'application_setting_id' => $setting->id,
                    'key' => $key,
                    'changed_by' => $userId,
                    'had_value_before' => $previousHadValue,
                    'has_value_after' => filled($incomingValue),
                    'changed_at' => now(),
                ]);
            }
        });

        Cache::forget(self::CACHE_KEY);
        $this->applyToConfig();
    }

    public function forget(string $key, int $userId): void
    {
        $setting = $this->setting($key);

        if (! $setting) {
            return;
        }

        $previousHadValue = filled($setting->value);
        $setting->update([
            'value' => null,
            'is_active' => false,
            'updated_by' => $userId,
        ]);

        ApplicationSettingAudit::query()->create([
            'application_setting_id' => $setting->id,
            'key' => $key,
            'changed_by' => $userId,
            'had_value_before' => $previousHadValue,
            'has_value_after' => false,
            'changed_at' => now(),
        ]);

        Cache::forget(self::CACHE_KEY);
        $this->applyToConfig();
    }

    private function setting(string $key): ?ApplicationSetting
    {
        try {
            if (! Schema::hasTable('application_settings')) {
                return null;
            }

            return ApplicationSetting::query()->where('key', $key)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseValues(): array
    {
        try {
            if (! Schema::hasTable('application_settings')) {
                return [];
            }

            return Cache::remember(self::CACHE_KEY, 300, fn (): array => ApplicationSetting::query()
                ->where('is_active', true)
                ->whereNotNull('value')
                ->get(['key', 'value'])
                ->mapWithKeys(fn (ApplicationSetting $setting): array => [$setting->key => $setting->value])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    private function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    private function rememberInitialConfigValue(string $configKey): void
    {
        if (array_key_exists($configKey, self::$initialConfigValues)) {
            return;
        }

        self::$initialConfigValues[$configKey] = config($configKey);
    }

    private function initialConfigValue(string $configKey): mixed
    {
        if (array_key_exists($configKey, self::$initialConfigValues)) {
            return self::$initialConfigValues[$configKey];
        }

        return config($configKey);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function hasEnvironmentValue(array $definition): bool
    {
        $envValue = isset($definition['env']) ? env($definition['env']) : null;

        if ($envValue !== null && $envValue !== '') {
            return true;
        }

        $configKey = $definition['config'] ?? null;

        if (! $configKey) {
            return false;
        }

        $configValue = $this->initialConfigValue($configKey);

        return $configValue !== null && $configValue !== '';
    }
}
