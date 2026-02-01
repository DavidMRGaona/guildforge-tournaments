<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum ConditionType: string
{
    case Result = 'result';
    case StatComparison = 'stat_comparison';
    case StatThreshold = 'stat_threshold';
    case MarginDifference = 'margin_diff';

    /**
     * Get human-readable label for the condition type.
     */
    public function label(): string
    {
        $key = match ($this) {
            self::Result => 'tournaments::messages.condition_type.result',
            self::StatComparison => 'tournaments::messages.condition_type.stat_comparison',
            self::StatThreshold => 'tournaments::messages.condition_type.stat_threshold',
            self::MarginDifference => 'tournaments::messages.condition_type.margin_diff',
        };

        try {
            return __($key);
        } catch (\Throwable) {
            return $key;
        }
    }

    /**
     * Get all condition type values.
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
