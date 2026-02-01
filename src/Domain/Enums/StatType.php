<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum StatType: string
{
    case Integer = 'integer';
    case Float = 'float';
    case Boolean = 'boolean';

    /**
     * Get human-readable label for the stat type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Integer => __('tournaments::messages.stat_type.integer'),
            self::Float => __('tournaments::messages.stat_type.float'),
            self::Boolean => __('tournaments::messages.stat_type.boolean'),
        };
    }

    /**
     * Get all stat type values.
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
