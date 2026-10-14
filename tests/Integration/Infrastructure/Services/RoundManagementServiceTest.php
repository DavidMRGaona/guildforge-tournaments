<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\CannotGeneratePairingsException;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\PreviousRoundNotCompletedException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Round generation (Swiss pairing) and result recording with standings, as the panel runs them.
 */
final class RoundManagementServiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_first_round_is_created_with_its_matches(): void
    {
        $tournament = $this->tournament();
        $participants = $this->participants($tournament, 4);

        $round = $this->rounds()->generateNextRound($tournament->id);

        $this->assertSame(1, $round->roundNumber);
        $this->assertSame(RoundStatus::Pending, $round->status);
        $this->assertSame(2, $round->matchCount);

        $matches = MatchModel::query()->where('round_id', $round->id)->get();
        $this->assertCount(2, $matches);
        $players = $matches->flatMap(static fn (MatchModel $m): array => [$m->player_1_id, $m->player_2_id])->sort()->values()->all();
        $this->assertSame(collect($participants)->pluck('id')->sort()->values()->all(), $players);
        $this->assertSame([1, 2], $matches->pluck('table_number')->sort()->values()->all());
        $this->assertSame(1, $tournament->refresh()->current_round);
    }

    public function test_an_odd_participant_gets_a_bye_that_scores_at_once(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 5);

        $round = $this->rounds()->generateNextRound($tournament->id);

        $bye = MatchModel::query()->where('round_id', $round->id)->whereNull('player_2_id')->sole();
        $this->assertSame(MatchResult::Bye, $bye->result);
        $this->assertTrue(ParticipantModel::query()->findOrFail($bye->player_1_id)->has_received_bye);

        $standing = StandingModel::query()->where('participant_id', $bye->player_1_id)->sole();
        $this->assertEquals(3.0, $standing->points);
        $this->assertSame(1, $standing->byes);
    }

    public function test_only_confirmed_and_checked_in_participants_are_paired(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $this->participants($tournament, 1, ParticipantStatus::CheckedIn);
        $this->participants($tournament, 1, ParticipantStatus::Withdrawn);
        $this->participants($tournament, 1, ParticipantStatus::Registered);

        $round = $this->rounds()->generateNextRound($tournament->id);

        // 3 playable participants: one match and one bye
        $this->assertSame(2, $round->matchCount);
    }

    public function test_a_round_cannot_be_generated_with_fewer_than_two_participants(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 1);

        $this->expectException(InsufficientParticipantsException::class);

        try {
            $this->rounds()->generateNextRound($tournament->id);
        } finally {
            $this->assertSame(0, RoundModel::query()->count());
        }
    }

    public function test_a_round_cannot_be_generated_unless_the_tournament_is_in_progress(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::RegistrationClosed->value]);
        $this->participants($tournament, 4);

        $this->expectException(CannotGeneratePairingsException::class);

        $this->rounds()->generateNextRound($tournament->id);
    }

    public function test_the_next_round_waits_for_the_previous_one_to_have_every_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $first = $this->rounds()->generateNextRound($tournament->id);
        RoundModel::query()->whereKey($first->id)->update(['status' => RoundStatus::Finished->value]);

        $this->expectException(PreviousRoundNotCompletedException::class);

        try {
            $this->rounds()->generateNextRound($tournament->id);
        } finally {
            $this->assertSame(1, RoundModel::query()->count());
        }
    }

    public function test_the_next_round_waits_for_the_previous_one_to_be_finished(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $first = $this->rounds()->generateNextRound($tournament->id);
        $this->reportAll($first->id);

        $this->expectException(PreviousRoundNotCompletedException::class);

        $this->rounds()->generateNextRound($tournament->id);
    }

    public function test_no_round_is_generated_beyond_the_maximum(): void
    {
        $tournament = $this->tournament(['max_rounds' => 1]);
        $this->participants($tournament, 4);
        $first = $this->rounds()->generateNextRound($tournament->id);
        $this->reportAll($first->id);
        RoundModel::query()->whereKey($first->id)->update(['status' => RoundStatus::Finished->value]);

        $this->expectException(CannotGeneratePairingsException::class);

        $this->rounds()->generateNextRound($tournament->id);
    }

    public function test_later_rounds_pair_by_score_and_avoid_rematches(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $first = $this->rounds()->generateNextRound($tournament->id);
        $firstMatches = MatchModel::query()->where('round_id', $first->id)->get();
        $winners = [];
        foreach ($firstMatches as $match) {
            $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::PlayerOneWin, $this->admin()->id));
            $winners[] = $match->player_1_id;
        }
        RoundModel::query()->whereKey($first->id)->update(['status' => RoundStatus::Finished->value]);

        $second = $this->rounds()->generateNextRound($tournament->id);

        $this->assertSame(2, $second->roundNumber);
        $secondMatches = MatchModel::query()->where('round_id', $second->id)->get();
        $winnersMatch = $secondMatches->first(static fn (MatchModel $m): bool => in_array($m->player_1_id, $winners, true));
        $this->assertNotNull($winnersMatch);
        $this->assertContains($winnersMatch->player_2_id, $winners);
        foreach ($secondMatches as $match) {
            foreach ($firstMatches as $previous) {
                $this->assertNotEqualsCanonicalizing([$previous->player_1_id, $previous->player_2_id], [$match->player_1_id, $match->player_2_id]);
            }
        }
    }

    public function test_recording_a_result_recalculates_the_standings(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $match = MatchModel::query()->where('round_id', $round->id)->sole();
        $admin = $this->admin();

        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::PlayerTwoWin, $admin->id, ['vp' => 40], ['vp' => 70]));

        $match->refresh();
        $this->assertSame(MatchResult::PlayerTwoWin, $match->result);
        $this->assertSame($admin->id, $match->reported_by_id);
        $this->assertNotNull($match->reported_at);
        $this->assertSame(['vp' => 70], $match->player_2_stats);

        $winner = StandingModel::query()->where('participant_id', $match->player_2_id)->sole();
        $loser = StandingModel::query()->where('participant_id', $match->player_1_id)->sole();
        $this->assertSame([1, 1, 3.0], [$winner->rank, $winner->wins, (float) $winner->points]);
        $this->assertSame([2, 1, 0.0], [$loser->rank, $loser->losses, (float) $loser->points]);
    }

    public function test_recording_a_result_stores_the_match_scores(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::PlayerOneWin, $this->admin()->id, player1Score: 2, player2Score: 1));

        $match->refresh();
        $this->assertSame([2, 1], [$match->player_1_score, $match->player_2_score]);
    }

    public function test_correcting_a_result_replaces_the_previous_one_in_the_standings(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $match = MatchModel::query()->where('round_id', $round->id)->sole();
        $admin = $this->admin();

        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::PlayerOneWin, $admin->id));
        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::Draw, $admin->id));

        $standing = StandingModel::query()->where('participant_id', $match->player_1_id)->sole();
        $this->assertSame([1, 0, 1, 1.0], [$standing->matches_played, $standing->wins, $standing->draws, (float) $standing->points]);
    }

    public function test_setting_a_match_back_to_not_played_clears_its_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $match = MatchModel::query()->where('round_id', $round->id)->sole();
        $admin = $this->admin();

        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::PlayerOneWin, $admin->id));
        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::NotPlayed, $admin->id));

        $match->refresh();
        $this->assertSame(MatchResult::NotPlayed, $match->result);
        $this->assertNull($match->reported_by_id);
        $this->assertSame(0, StandingModel::query()->where('participant_id', $match->player_1_id)->sole()->matches_played);
    }

    public function test_a_bye_cannot_be_recorded_for_a_match_between_two_players(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 2);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        $this->expectException(InvalidMatchResultException::class);

        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::Bye, $this->admin()->id));
    }

    public function test_a_bye_match_only_accepts_the_bye_result(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 3);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $bye = MatchModel::query()->where('round_id', $round->id)->whereNull('player_2_id')->sole();

        $this->expectException(InvalidMatchResultException::class);

        $this->results()->recordResult(new RecordMatchResultDTO($bye->id, MatchResult::PlayerOneWin, $this->admin()->id));
    }

    public function test_the_game_profile_rules_apply_when_the_tournament_has_none(): void
    {
        $profile = GameProfileModel::query()->where('is_system', true)->firstOrFail()->replicate(['id', 'slug']);
        $profile->name = 'Perfil propio';
        $profile->is_system = false;
        $profile->forceFill(['scoring_rules' => [
            ['name' => 'Victoria', 'points' => 2, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
        ]])->save();
        $tournament = $this->tournament(['scoring_rules' => null, 'tiebreaker_config' => null, 'game_profile_id' => $profile->id]);

        $this->assertSame(2.0, $this->pointsOfAWinner($tournament));
    }

    public function test_default_rules_apply_without_tournament_or_profile_rules(): void
    {
        $tournament = $this->tournament(['scoring_rules' => null, 'tiebreaker_config' => null]);

        $this->assertSame(3.0, $this->pointsOfAWinner($tournament));
    }

    public function test_standings_follow_head_to_head_then_a_random_order_kept_across_recalculations(): void
    {
        $tournament = $this->tournament(['tiebreaker_config' => [
            ['key' => 'h2h', 'name' => 'Enfrentamiento directo', 'type' => 'head_to_head', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
            ['key' => 'random', 'name' => 'Aleatorio', 'type' => 'random', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
        ]]);
        [$e, $f, $g, $h] = $this->participants($tournament, 4);
        // E beats F, then F beats G and H beats E: E, F and H on 3 points; F lost to E, E and H split
        $this->playRound($tournament, 1, [[$e, $f, MatchResult::PlayerOneWin], [$g, $h, MatchResult::PlayerTwoWin]]);
        $this->playRound($tournament, 2, [[$f, $g, MatchResult::PlayerOneWin], [$h, $e, MatchResult::PlayerOneWin]]);

        $standings = app(StandingsServiceInterface::class);
        $first = $this->ranks($standings->recalculate($tournament->id));
        $seed = $tournament->refresh()->random_seed;
        $second = $this->ranks($standings->recalculate($tournament->id));

        // H has 6 points; E (h2h 3: beat F) before F (h2h 0); G last
        $this->assertSame([$h->id, $e->id, $f->id, $g->id], $first);
        $this->assertNotNull($seed);
        $this->assertSame($seed, $tournament->refresh()->random_seed);
        $this->assertSame($first, $second);
    }

    /**
     * @param  list<array{ParticipantModel, ParticipantModel, MatchResult}>  $results
     */
    private function playRound(TournamentModel $tournament, int $number, array $results): void
    {
        $round = RoundModel::create(['id' => (string) Str::uuid(), 'tournament_id' => $tournament->id, 'round_number' => $number, 'status' => RoundStatus::Finished->value]);
        foreach ($results as $table => [$player1, $player2, $result]) {
            MatchModel::create(['id' => (string) Str::uuid(), 'round_id' => $round->id, 'player_1_id' => $player1->id, 'player_2_id' => $player2->id, 'table_number' => $table + 1, 'result' => $result->value]);
        }
    }

    /**
     * @param  array<\Modules\Tournaments\Domain\Entities\Standing>  $standings
     * @return list<string>
     */
    private function ranks(array $standings): array
    {
        usort($standings, static fn ($a, $b): int => $a->rank() <=> $b->rank());

        return array_map(static fn ($standing): string => $standing->participantId(), $standings);
    }

    private function pointsOfAWinner(TournamentModel $tournament): float
    {
        $this->participants($tournament, 2);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $match = MatchModel::query()->where('round_id', $round->id)->sole();

        $this->results()->recordResult(new RecordMatchResultDTO($match->id, MatchResult::PlayerOneWin, $this->admin()->id));

        return (float) StandingModel::query()->where('participant_id', $match->player_1_id)->sole()->points;
    }

    private function rounds(): RoundManagementServiceInterface
    {
        return app(RoundManagementServiceInterface::class);
    }

    private function results(): MatchResultServiceInterface
    {
        return app(MatchResultServiceInterface::class);
    }

    private function admin(): UserModel
    {
        return UserModel::factory()->admin()->create();
    }

    private function reportAll(string $roundId): void
    {
        $admin = $this->admin();
        MatchModel::query()->where('round_id', $roundId)->where('result', MatchResult::NotPlayed->value)->get()
            ->each(fn (MatchModel $m) => $this->results()->recordResult(new RecordMatchResultDTO($m->id, MatchResult::PlayerOneWin, $admin->id)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo suizo',
            'slug' => 'torneo-suizo-'.Str::random(6),
            'status' => TournamentStatus::InProgress->value,
            'result_reporting' => 'admin_only',
            'scoring_rules' => [
                ['name' => 'Victoria', 'points' => 3, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
                ['name' => 'Empate', 'points' => 1, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'draw', 'stat' => null, 'operator' => null, 'value' => null]],
                ['name' => 'Derrota', 'points' => 0, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'loss', 'stat' => null, 'operator' => null, 'value' => null]],
                ['name' => 'Bye', 'points' => 3, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'bye', 'stat' => null, 'operator' => null, 'value' => null]],
            ],
            'tiebreaker_config' => [
                ['key' => 'buchholz', 'name' => 'Buchholz', 'type' => 'buchholz', 'stat' => null, 'direction' => 'desc', 'min_value' => null],
            ],
            ...$attributes,
        ])->refresh();
    }

    /**
     * @return list<ParticipantModel>
     */
    private function participants(TournamentModel $tournament, int $count, ParticipantStatus $status = ParticipantStatus::Confirmed): array
    {
        $participants = [];
        for ($i = 0; $i < $count; $i++) {
            $participants[] = ParticipantModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'guest_name' => 'Jugador '.Str::random(4),
                'guest_email' => Str::random(8).'@example.com',
                'status' => $status->value,
                'registered_at' => now(),
            ]);
        }

        return $participants;
    }
}
