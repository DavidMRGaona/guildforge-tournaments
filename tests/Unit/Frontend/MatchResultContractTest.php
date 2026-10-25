<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source guards, not behaviour tests: the module has no Vue test runner. The TS unions,
 * the color maps and the JS locales must follow the PHP enums case by case.
 */
final class MatchResultContractTest extends TestCase
{
    private const string JS = __DIR__.'/../../../resources/js/';

    public function test_ts_unions_list_exactly_the_enum_values(): void
    {
        $types = $this->source('types/tournaments.ts');

        $this->assertSame(MatchResult::values(), $this->unionValues($types, 'MatchResult'));
        $this->assertSame(TournamentStatus::values(), $this->unionValues($types, 'TournamentStatus'));
        $this->assertSame(ParticipantStatus::values(), $this->unionValues($types, 'ParticipantStatus'));
        $this->assertSame(RoundStatus::values(), $this->unionValues($types, 'RoundStatus'));
    }

    public function test_every_color_map_entry_equals_the_server_color(): void
    {
        $composable = $this->source('composables/useTournaments.ts');

        foreach (TournamentStatus::cases() as $case) {
            $this->assertMapEntry($composable, 'TOURNAMENT_STATUS_COLORS', $case->value, $case->color());
        }
        // Display-only status, sent by TournamentResponseDTO::displayStatusColor()
        $this->assertMapEntry($composable, 'TOURNAMENT_STATUS_COLORS', 'registration_upcoming', 'info');
        foreach (ParticipantStatus::cases() as $case) {
            $this->assertMapEntry($composable, 'PARTICIPANT_STATUS_COLORS', $case->value, $case->color());
        }
        foreach (RoundStatus::cases() as $case) {
            $this->assertMapEntry($composable, 'ROUND_STATUS_COLORS', $case->value, $case->color());
        }
        foreach (MatchResult::cases() as $case) {
            $this->assertMapEntry($composable, 'MATCH_RESULT_COLORS', $case->value, $case->color());
        }
    }

    public function test_the_composable_no_longer_returns_color_words(): void
    {
        $composable = $this->source('composables/useTournaments.ts');

        foreach (['green', 'yellow', 'blue', 'purple', 'red'] as $word) {
            $this->assertStringNotContainsString("'{$word}'", $composable);
        }
        foreach (['player1_win', 'player2_win'] as $old) {
            $this->assertStringNotContainsString($old, $composable);
        }
    }

    public function test_the_tournament_page_badge_uses_the_shared_map(): void
    {
        $show = $this->source('pages/Tournaments/Show.vue');

        $this->assertStringContainsString('statusBadgeClasses(statusInfo.color)', $show);
        $this->assertStringNotContainsString("statusInfo.color === 'green'", $show);
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function matchResultLocales(): array
    {
        return [
            'es' => ['locales/es.ts', [
                'player_one_win' => 'Victoria jugador 1',
                'player_two_win' => 'Victoria jugador 2',
                'draw' => 'Empate',
                'double_loss' => 'Doble derrota',
                'bye' => 'Bye',
                'not_played' => 'Sin jugar',
            ]],
            'en' => ['locales/en.ts', [
                'player_one_win' => 'Player 1 wins',
                'player_two_win' => 'Player 2 wins',
                'draw' => 'Draw',
                'double_loss' => 'Double loss',
                'bye' => 'Bye',
                'not_played' => 'Not played',
            ]],
        ];
    }

    /**
     * @param  array<string, string>  $expected
     */
    #[DataProvider('matchResultLocales')]
    public function test_the_js_locales_have_one_label_per_enum_value(string $file, array $expected): void
    {
        $this->assertSame(array_keys($expected), MatchResult::values());

        $matched = preg_match('/match_result: \{(.*?)\},/s', $this->source($file), $block);
        $this->assertSame(1, $matched, 'match_result block');

        foreach ($expected as $key => $label) {
            $this->assertStringContainsString("{$key}: '{$label}'", $block[1]);
        }
        $this->assertStringNotContainsString('player1_win', $block[1]);
        $this->assertStringNotContainsString('pending:', $block[1]);
    }

    public function test_the_rounds_page_highlights_winners_and_colors_badges_from_server_values(): void
    {
        $rounds = $this->source('pages/Tournaments/Rounds.vue');

        $this->assertStringContainsString("match.result === 'player_one_win'", $rounds);
        $this->assertStringContainsString("match.result === 'player_two_win'", $rounds);
        $this->assertStringNotContainsString('player1_win', $rounds);
        $this->assertStringNotContainsString('player2_win', $rounds);
        $compact = (string) preg_replace('/\s+/', '', $rounds);
        $this->assertStringContainsString('statusBadgeClasses(getMatchResultColor(match.result))', $compact);
        $this->assertStringContainsString('statusBadgeClasses(getRoundStatusColor(roundData.round.status))', $compact);
        $this->assertStringNotContainsString("purple: 'bg-info-light", $rounds);
    }

    private function assertMapEntry(string $source, string $map, string $key, string $color): void
    {
        $matched = preg_match('/const '.$map.': Record<[^>]+> = \{(.*?)\n\};/s', $source, $block);
        $this->assertSame(1, $matched, "{$map} is declared");
        $this->assertStringContainsString("{$key}: '{$color}'", $block[1], "{$map}.{$key}");
    }

    /**
     * @return list<string>
     */
    private function unionValues(string $source, string $type): array
    {
        $matched = preg_match('/export type '.$type.' =(.*?);/s', $source, $block);
        $this->assertSame(1, $matched, "{$type} is declared");
        preg_match_all("/'([a-z_0-9]+)'/", $block[1], $values);

        return $values[1];
    }

    private function source(string $file): string
    {
        $source = file_get_contents(self::JS.$file);
        $this->assertIsString($source);

        return $source;
    }
}
