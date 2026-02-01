<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum ParticipantStatus: string
{
    case Registered = 'registered';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Withdrawn = 'withdrawn';
    case Disqualified = 'disqualified';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Registered => __('tournaments::messages.participant_status.registered'),
            self::Confirmed => __('tournaments::messages.participant_status.confirmed'),
            self::CheckedIn => __('tournaments::messages.participant_status.checked_in'),
            self::Withdrawn => __('tournaments::messages.participant_status.withdrawn'),
            self::Disqualified => __('tournaments::messages.participant_status.disqualified'),
        };
    }

    /**
     * Get badge color for Filament UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::Registered => 'warning',
            self::Confirmed => 'info',
            self::CheckedIn => 'success',
            self::Withdrawn => 'gray',
            self::Disqualified => 'danger',
        };
    }

    /**
     * Check if transition to the given status is allowed.
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::Registered => in_array($newStatus, [self::Confirmed, self::Withdrawn], true),
            self::Confirmed => in_array($newStatus, [self::CheckedIn, self::Withdrawn, self::Disqualified], true),
            self::CheckedIn => in_array($newStatus, [self::Withdrawn, self::Disqualified], true),
            self::Withdrawn => $newStatus === self::Registered,
            self::Disqualified => false,
        };
    }

    /**
     * Check if the participant is in an active state.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Registered, self::Confirmed, self::CheckedIn], true);
    }

    /**
     * Check if the participant is in a final state.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Withdrawn, self::Disqualified], true);
    }

    /**
     * Check if the participant can play matches.
     */
    public function canPlay(): bool
    {
        return in_array($this, [self::Confirmed, self::CheckedIn], true);
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
