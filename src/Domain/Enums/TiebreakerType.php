<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Enums;

enum TiebreakerType: string
{
    // Classic Swiss tiebreakers
    case Buchholz = 'buchholz';
    case MedianBuchholz = 'median_buchholz';
    case Progressive = 'progressive';
    case OpponentWinPercentage = 'owp';
    case OpponentOpponentWinPercentage = 'oowp';
    case GameWinPercentage = 'gwp';
    case OpponentGameWinPercentage = 'ogwp';
    case HeadToHead = 'head_to_head';
    case SonnebornBerger = 'sonneborn_berger';

    // Stat-based tiebreakers
    case StatSum = 'stat_sum';
    case StatDiff = 'stat_diff';
    case StatAverage = 'stat_average';
    case StatMax = 'stat_max';

    // Special
    case StrengthOfSchedule = 'sos';
    case MarginOfVictory = 'mov';
    case Random = 'random';

    /**
     * Get all tiebreaker type values.
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
     * Get human-readable label for the tiebreaker type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Buchholz => __('tournaments::messages.tiebreaker_type.buchholz'),
            self::MedianBuchholz => __('tournaments::messages.tiebreaker_type.median_buchholz'),
            self::Progressive => __('tournaments::messages.tiebreaker_type.progressive'),
            self::OpponentWinPercentage => __('tournaments::messages.tiebreaker_type.owp'),
            self::OpponentOpponentWinPercentage => __('tournaments::messages.tiebreaker_type.oowp'),
            self::GameWinPercentage => __('tournaments::messages.tiebreaker_type.gwp'),
            self::OpponentGameWinPercentage => __('tournaments::messages.tiebreaker_type.ogwp'),
            self::HeadToHead => __('tournaments::messages.tiebreaker_type.head_to_head'),
            self::SonnebornBerger => __('tournaments::messages.tiebreaker_type.sonneborn_berger'),
            self::StatSum => __('tournaments::messages.tiebreaker_type.stat_sum'),
            self::StatDiff => __('tournaments::messages.tiebreaker_type.stat_diff'),
            self::StatAverage => __('tournaments::messages.tiebreaker_type.stat_average'),
            self::StatMax => __('tournaments::messages.tiebreaker_type.stat_max'),
            self::StrengthOfSchedule => __('tournaments::messages.tiebreaker_type.sos'),
            self::MarginOfVictory => __('tournaments::messages.tiebreaker_type.mov'),
            self::Random => __('tournaments::messages.tiebreaker_type.random'),
        };
    }

    /**
     * Check if this tiebreaker type requires a stat configuration.
     */
    public function requiresStat(): bool
    {
        return match ($this) {
            self::StatSum,
            self::StatDiff,
            self::StatAverage,
            self::StatMax => true,
            default => false,
        };
    }
}
