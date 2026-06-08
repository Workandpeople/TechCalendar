<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\ApplicationSettingAudit;
use Illuminate\Support\Facades\Cache;
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
        return config('application_settings.definitions', []);
    }

    public function applyToConfig(): void
    {
        foreach ($this->definitions() as $key => $definition) {
            $configKey = $definition['config'] ?? null;

            if (! $configKey) {
                continue;
            }

            $this->rememberInitialConfigValue($configKey);

            $value = $this->get($key);

            if ($value === null) {
                continue;
            }

            config([$configKey => $value]);
        }
    }

    public function get(string $key): mixed
    {
        $definition = $this->definitions()[$key] ?? null;

        if (! $definition) {
            return null;
        }

        $databaseValue = $this->databaseValues()[$key] ?? null;

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
        return collect($this->definitions())
            ->map(function (array $definition, string $key): array {
                $setting = $this->setting($key);
                $hasDatabaseValue = $setting && filled($setting->value);

                return [
                    'key' => $key,
                    'group' => $definition['group'],
                    'label' => $definition['label'],
                    'type' => $definition['type'] ?? 'string',
                    'description' => $definition['description'] ?? null,
                    'options' => $definition['options'] ?? [],
                    'is_secret' => (bool) ($definition['secret'] ?? false),
                    'value' => $hasDatabaseValue && ! ($definition['secret'] ?? false) ? $setting->value : $this->get($key),
                    'has_database_value' => $hasDatabaseValue,
                    'source' => $hasDatabaseValue ? 'bdd' : 'env',
                    'updated_at' => $setting?->updated_at,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values, int $userId): void
    {
        foreach ($this->definitions() as $key => $definition) {
            $incomingValue = $values[$key] ?? null;
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
}
