<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum TournamentStatus: string
{
    case Draft = 'draft';
    case RegistrationOpen = 'registration_open';
    case RegistrationClosed = 'registration_closed';
    case InProgress = 'in_progress';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('tournaments::messages.tournament_status.draft'),
            self::RegistrationOpen => __('tournaments::messages.tournament_status.registration_open'),
            self::RegistrationClosed => __('tournaments::messages.tournament_status.registration_closed'),
            self::InProgress => __('tournaments::messages.tournament_status.in_progress'),
            self::Finished => __('tournaments::messages.tournament_status.finished'),
            self::Cancelled => __('tournaments::messages.tournament_status.cancelled'),
        };
    }

    /**
     * Get badge color for Filament UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::RegistrationOpen => 'success',
            self::RegistrationClosed => 'warning',
            self::InProgress => 'info',
            self::Finished => 'primary',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Check if transitions to the given status is allowed.
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::Draft => in_array($newStatus, [self::RegistrationOpen, self::Cancelled], true),
            self::RegistrationOpen => in_array($newStatus, [self::RegistrationClosed, self::Cancelled], true),
            self::RegistrationClosed => in_array($newStatus, [self::InProgress, self::Cancelled], true),
            self::InProgress => in_array($newStatus, [self::Finished, self::Cancelled], true),
            self::Finished, self::Cancelled => false,
        };
    }

    /**
     * Check if registration is currently open.
     */
    public function isRegistrationOpen(): bool
    {
        return $this === self::RegistrationOpen;
    }

    /**
     * Check if the tournament is in an active state.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::RegistrationOpen, self::RegistrationClosed, self::InProgress], true);
    }

    /**
     * Check if the tournament is in a final state (cannot be changed).
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Finished, self::Cancelled], true);
    }

    /**
     * Get all status values.
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
