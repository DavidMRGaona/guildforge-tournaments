<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament;

/**
 * Initial values of the forms that create a tournament, from the module settings
 * ("Valores por defecto"), falling back to the settings form defaults.
 */
final class TournamentFormDefaults
{
    public static function resultReporting(): string
    {
        $value = self::setting('default_result_reporting');

        return is_string($value) && $value !== '' ? $value : 'admin_only';
    }

    public static function allowGuests(): bool
    {
        return (bool) (self::setting('default_allow_guests') ?? false);
    }

    public static function requiresCheckIn(): bool
    {
        return (bool) (self::setting('default_requires_check_in') ?? false);
    }

    public static function checkInStartsBefore(): int
    {
        $value = self::setting('default_check_in_starts_before');

        return is_numeric($value) ? (int) $value : 30;
    }

    private static function setting(string $key): mixed
    {
        return config("modules.settings.tournaments.{$key}");
    }
}
