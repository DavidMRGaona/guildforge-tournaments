<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';

    /**
     * Get human-readable label for the sort direction.
     */
    public function label(): string
    {
        return match ($this) {
            self::Ascending => __('tournaments::messages.sort_direction.ascending'),
            self::Descending => __('tournaments::messages.sort_direction.descending'),
        };
    }

    /**
     * Get all sort direction values.
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
