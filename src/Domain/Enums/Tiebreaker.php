<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum Tiebreaker: string
{
    case Buchholz = 'buchholz';
    case MedianBuchholz = 'median_buchholz';
    case Progressive = 'progressive';
    case HeadToHead = 'head_to_head';
    case OpponentWinPercentage = 'opponent_win_percentage';

    /**
     * Get human-readable label for the tiebreaker.
     */
    public function label(): string
    {
        return match ($this) {
            self::Buchholz => __('tournaments::messages.tiebreaker.buchholz'),
            self::MedianBuchholz => __('tournaments::messages.tiebreaker.median_buchholz'),
            self::Progressive => __('tournaments::messages.tiebreaker.progressive'),
            self::HeadToHead => __('tournaments::messages.tiebreaker.head_to_head'),
            self::OpponentWinPercentage => __('tournaments::messages.tiebreaker.opponent_win_percentage'),
        };
    }

    /**
     * Get all tiebreaker values.
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

    /**
     * Create tiebreaker instances from an array of string values.
     *
     * @param  array<string>  $values
     * @return array<self>
     */
    public static function fromArray(array $values): array
    {
        return array_map(
            static fn (string $value): self => self::from($value),
            $values
        );
    }

    /**
     * Convert an array of tiebreakers to their string values.
     *
     * @param  array<self>  $tiebreakers
     * @return array<string>
     */
    public static function toValues(array $tiebreakers): array
    {
        return array_map(
            static fn (self $tiebreaker): string => $tiebreaker->value,
            $tiebreakers
        );
    }
}
