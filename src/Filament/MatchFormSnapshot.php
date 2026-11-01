<?php

declare(strict_types=1);

namespace Modules\Tournaments\Filament;

use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;

/**
 * A match's result, scores and stats as one comparable string: «Gestionar partidas» keeps the
 * one it opened with, records only the rows the organizer changed and leaves alone those that
 * changed meanwhile (a player's report, another organizer).
 */
final class MatchFormSnapshot
{
    /**
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    public static function of(string $result, ?int $player1Score, ?int $player2Score, ?array $player1Stats, ?array $player2Stats): string
    {
        return (string) json_encode([
            $result,
            $player1Score,
            $player2Score,
            self::normalized($player1Stats),
            self::normalized($player2Stats),
        ]);
    }

    public static function ofMatch(MatchModel $match): string
    {
        return self::of($match->result->value, $match->player_1_score, $match->player_2_score, $match->player_1_stats, $match->player_2_stats);
    }

    /**
     * Empty values left out, keys sorted and numeric strings as numbers, so the form's state and
     * the stored stats compare equal when they say the same.
     *
     * @param  array<string, mixed>|null  $stats
     * @return array<string, mixed>|null
     */
    private static function normalized(?array $stats): ?array
    {
        $kept = [];
        foreach ($stats ?? [] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $kept[$key] = is_string($value) && is_numeric($value) ? $value + 0 : $value;
        }

        ksort($kept);

        return $kept === [] ? null : $kept;
    }
}
