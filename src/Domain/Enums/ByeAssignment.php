<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum ByeAssignment: string
{
    case LowestRanked = 'lowest_ranked';
    case Random = 'random';
    case HighestRanked = 'highest_ranked';

    /**
     * Get human-readable label for the bye assignment method.
     */
    public function label(): string
    {
        return match ($this) {
            self::LowestRanked => __('tournaments::messages.bye_assignment.lowest_ranked'),
            self::Random => __('tournaments::messages.bye_assignment.random'),
            self::HighestRanked => __('tournaments::messages.bye_assignment.highest_ranked'),
        };
    }

    /**
     * Get all bye assignment values.
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
