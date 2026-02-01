<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum ResultReporting: string
{
    case AdminOnly = 'admin_only';
    case PlayersWithConfirmation = 'players_with_confirmation';
    case PlayersTrusted = 'players_trusted';

    /**
     * Get human-readable label for the reporting mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::AdminOnly => __('tournaments::messages.result_reporting.admin_only'),
            self::PlayersWithConfirmation => __('tournaments::messages.result_reporting.players_with_confirmation'),
            self::PlayersTrusted => __('tournaments::messages.result_reporting.players_trusted'),
        };
    }

    /**
     * Check if players are allowed to report results.
     */
    public function allowsPlayerReporting(): bool
    {
        return $this !== self::AdminOnly;
    }

    /**
     * Check if result confirmation is required from opponent.
     */
    public function requiresConfirmation(): bool
    {
        return $this === self::PlayersWithConfirmation;
    }

    /**
     * Get all reporting mode values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get options for form select fields.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
