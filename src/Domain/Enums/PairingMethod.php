<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum PairingMethod: string
{
    case Swiss = 'swiss';
    case Random = 'random';
    case Accelerated = 'accelerated';

    /**
     * Get human-readable label for the pairing method.
     */
    public function label(): string
    {
        return match ($this) {
            self::Swiss => __('tournaments::messages.pairing_method.swiss'),
            self::Random => __('tournaments::messages.pairing_method.random'),
            self::Accelerated => __('tournaments::messages.pairing_method.accelerated'),
        };
    }

    /**
     * Get all pairing method values.
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
