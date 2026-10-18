<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Application\Services\GuestRegistrationSettingsInterface;
use Modules\Tournaments\Domain\Enums\GuestAccountPolicy;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;

/**
 * Reads the values saved in the panel (config 'modules.settings.tournaments') merged over the
 * shipped defaults (config/settings.php of the module).
 */
final class GuestRegistrationSettings implements GuestRegistrationSettingsInterface
{
    /** @var array<string, mixed>|null */
    private static ?array $shippedDefaults = null;

    public function confirmationMode(): GuestConfirmationMode
    {
        $value = $this->get('guest_confirmation.mode');

        return (is_string($value) ? GuestConfirmationMode::tryFrom($value) : null) ?? GuestConfirmationMode::Hold;
    }

    public function holdHours(): int
    {
        return $this->positiveInt('guest_confirmation.hold_hours', 2);
    }

    public function maxUnconfirmedHeld(): int
    {
        $value = $this->get('guest_confirmation.max_unconfirmed_held');

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : 2;
    }

    public function accountPolicy(): GuestAccountPolicy
    {
        $value = $this->get('guest_accounts.mode');

        return (is_string($value) ? GuestAccountPolicy::tryFrom($value) : null) ?? GuestAccountPolicy::RequireLogin;
    }

    public function perMinute(): int
    {
        return $this->positiveInt('guest_rate_limit.per_minute', 5);
    }

    public function perHour(): int
    {
        return $this->positiveInt('guest_rate_limit.per_hour', 20);
    }

    public function duplicateWindowHours(): int
    {
        return $this->positiveInt('duplicate_signal.window_hours', 24);
    }

    private function positiveInt(string $key, int $fallback): int
    {
        $value = $this->get($key);

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : $fallback;
    }

    private function get(string $key): mixed
    {
        return data_get($this->all(), $key);
    }

    /**
     * Panel values over the shipped defaults.
     *
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $stored = config('modules.settings.tournaments');

        return is_array($stored) ? self::merge($this->defaults(), $stored) : $this->defaults();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        if (self::$shippedDefaults === null) {
            $file = dirname(__DIR__, 3).'/config/settings.php';
            $defaults = is_file($file) ? (static fn (string $path): mixed => require $path)($file) : [];
            self::$shippedDefaults = is_array($defaults) ? $defaults : [];
        }

        return self::$shippedDefaults;
    }

    /**
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $stored
     * @return array<mixed>
     */
    private static function merge(array $defaults, array $stored): array
    {
        foreach ($stored as $key => $value) {
            // A value the panel left empty keeps the default
            if ($value === null) {
                continue;
            }

            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($value) && is_array($default) && ! array_is_list($value) && ! array_is_list($default)
                ? self::merge($default, $value)
                : $value;
        }

        return $defaults;
    }
}
