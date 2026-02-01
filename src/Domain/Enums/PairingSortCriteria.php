<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

use Illuminate\Contracts\Container\BindingResolutionException;

enum PairingSortCriteria: string
{
    case Points = 'points';
    case Stat = 'stat';
    case Random = 'random';

    /**
     * Get human-readable label for the criteria.
     */
    public function label(): string
    {
        $key = match ($this) {
            self::Points => 'tournaments::messages.pairing_sort_criteria.points',
            self::Stat => 'tournaments::messages.pairing_sort_criteria.stat',
            self::Random => 'tournaments::messages.pairing_sort_criteria.random',
        };

        try {
            return __($key);
        } catch (BindingResolutionException) {
            return ucfirst($this->value);
        }
    }

    /**
     * Get all criteria values.
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
