<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum RoundStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Finished = 'finished';

    /**
     * Get human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('tournaments::messages.round_status.pending'),
            self::InProgress => __('tournaments::messages.round_status.in_progress'),
            self::Finished => __('tournaments::messages.round_status.finished'),
        };
    }

    /**
     * Get badge color for Filament UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'info',
            self::Finished => 'success',
        };
    }

    /**
     * Check if transition to the given status is allowed.
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return match ($this) {
            self::Pending => $newStatus === self::InProgress,
            self::InProgress => $newStatus === self::Finished,
            self::Finished => false,
        };
    }

    /**
     * Check if the round is currently active.
     */
    public function isActive(): bool
    {
        return $this === self::InProgress;
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
