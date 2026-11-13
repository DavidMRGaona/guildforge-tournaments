<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Modules\Tournaments\Domain\Enums\ConditionType;
use Modules\Tournaments\Domain\Enums\SortDirection;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;

/**
 * The scoring rules and tiebreakers a tournament starts with when no game profile gives its own:
 * the panel's create form and the tournaments built without it (event proposals) share them.
 */
final class TournamentDefaultRules
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function scoringRules(): array
    {
        return array_map(
            static fn (string $result, int $points): array => [
                'name' => __("tournaments::messages.condition_result.{$result}"),
                'points' => $points,
                'priority' => 5,
                'condition' => [
                    'type' => ConditionType::Result->value,
                    'result_value' => $result,
                    'stat' => null,
                    'operator' => null,
                    'value' => null,
                ],
            ],
            ['win', 'draw', 'loss', 'bye'],
            [3, 1, 0, 3],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function tiebreakers(): array
    {
        return [
            [
                'key' => 'buchholz',
                'name' => __('tournaments::messages.tiebreaker.buchholz'),
                'type' => TiebreakerType::Buchholz->value,
                'direction' => SortDirection::Descending->value,
            ],
            [
                'key' => 'progressive',
                'name' => __('tournaments::messages.tiebreaker.progressive'),
                'type' => TiebreakerType::Progressive->value,
                'direction' => SortDirection::Descending->value,
            ],
        ];
    }

    /**
     * @return list<ScoringRule>
     */
    public static function scoringRuleObjects(): array
    {
        return array_values(array_map(ScoringRule::fromArray(...), self::scoringRules()));
    }

    /**
     * @return list<TiebreakerDefinition>
     */
    public static function tiebreakerObjects(): array
    {
        return array_values(array_map(TiebreakerDefinition::fromArray(...), self::tiebreakers()));
    }
}
