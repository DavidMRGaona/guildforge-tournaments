<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Application\Activity\Contracts\OffersActivityAttributes;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Domain\Activity\ActivityKind;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Activity\TournamentActivitySource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\BuildsFinishedTournaments;
use Tests\Support\Activity\ActivitySourceTestCase;

/**
 * What a finished tournament says about the people in it (core#774): the contract's checks, and the
 * module's own rules about who counts and what is left out.
 */
final class TournamentActivitySourceTest extends ActivitySourceTestCase
{
    use BuildsFinishedTournaments;

    protected ?string $moduleName = 'tournaments';

    protected function activitySource(): ActivitySourceInterface
    {
        return $this->app->make(TournamentActivitySource::class);
    }

    protected function createCountedActivitySubject(): string
    {
        $tournament = $this->finishedTournament(['game_profile_id' => $this->gameProfile('Warhammer')->id]);
        $this->player($tournament, 1, 9.0);
        $this->player($tournament, 2, 6.0);

        return $tournament->id;
    }

    /**
     * @return array<string, ActivityFactDTO> The facts of a subject by "<user>|<role>"
     */
    private function factsByKey(string $subjectId): array
    {
        $facts = [];
        foreach ($this->activitySource()->facts($subjectId) as $fact) {
            $facts[$fact->userId.'|'.$fact->role->value] = $fact;
        }

        return $facts;
    }

    public function test_it_is_a_tournament_source(): void
    {
        $source = $this->activitySource();

        $this->assertSame('tournaments.tournament', $source->type());
        $this->assertSame(ActivityKind::Tournament, $source->kind());
        $this->assertNotSame('', $source->label());
        $this->assertInstanceOf(OffersActivityAttributes::class, $source);
    }

    public function test_players_get_their_placement(): void
    {
        $profile = $this->gameProfile('Warhammer');
        $tournament = $this->finishedTournament(['game_profile_id' => $profile->id]);
        $winner = UserModel::factory()->create();
        $second = UserModel::factory()->create();
        $this->player($tournament, 1, 9.0, user: $winner);
        $this->player($tournament, 2, 6.0, user: $second);

        $facts = $this->factsByKey($tournament->id);

        $this->assertCount(2, $facts);
        $this->assertSame(1, $facts["{$winner->id}|player"]->placement);
        $this->assertSame(2, $facts["{$second->id}|player"]->placement);

        foreach ($facts as $fact) {
            $this->assertSame($profile->id, $fact->attributes['tournament_profile']->id);
            $this->assertSame('Warhammer', $fact->attributes['tournament_profile']->label);
            $this->assertSame($tournament->completed_at?->getTimestamp(), $fact->occurredAt->getTimestamp());
        }
    }

    public function test_a_tournament_without_game_profile_has_no_attribute(): void
    {
        $tournament = $this->finishedTournament();
        $this->player($tournament, 1, 3.0);

        $this->assertSame([], $this->activitySource()->facts($tournament->id)[0]->attributes);
    }

    public function test_players_with_the_same_points_and_tiebreakers_share_the_placement(): void
    {
        $tournament = $this->finishedTournament();
        $a = $this->player($tournament, 1, 6.0);
        $b = $this->player($tournament, 2, 6.0);
        $c = $this->player($tournament, 3, 3.0);

        $placements = [];
        foreach ($this->activitySource()->facts($tournament->id) as $fact) {
            $placements[] = [$fact->userId, $fact->placement];
        }

        $this->assertEqualsCanonicalizing(
            [[$a->user_id, 1], [$b->user_id, 1], [$c->user_id, 3]],
            $placements,
        );
    }

    public function test_a_guest_is_never_reported_but_takes_their_place_in_the_table(): void
    {
        $tournament = $this->finishedTournament();
        $this->guestPlayer($tournament, 1, 9.0);
        $member = $this->player($tournament, 2, 6.0);

        $facts = $this->activitySource()->facts($tournament->id);

        $this->assertCount(1, $facts);
        $this->assertSame($member->user_id, $facts[0]->userId);
        $this->assertSame(2, $facts[0]->placement);
    }

    public function test_withdrawn_and_disqualified_players_are_not_reported_and_nobody_moves_up(): void
    {
        $tournament = $this->finishedTournament();
        $this->player($tournament, 1, 9.0, ParticipantStatus::Disqualified);
        $this->player($tournament, 2, 6.0, ParticipantStatus::Withdrawn);
        $kept = $this->player($tournament, 3, 3.0);

        $facts = $this->activitySource()->facts($tournament->id);

        $this->assertCount(1, $facts);
        $this->assertSame($kept->user_id, $facts[0]->userId);
        // The place is the public table's: the ones left out still hold theirs
        $this->assertSame(3, $facts[0]->placement);
    }

    public function test_the_winner_who_withdrew_gets_nothing_and_the_runner_up_stays_second(): void
    {
        $tournament = $this->finishedTournament();
        $this->player($tournament, 1, 9.0, ParticipantStatus::Withdrawn);
        $bob = $this->player($tournament, 2, 6.0);

        $facts = $this->activitySource()->facts($tournament->id);

        $this->assertCount(1, $facts);
        $this->assertSame($bob->user_id, $facts[0]->userId);
        $this->assertSame(2, $facts[0]->placement);
    }

    public function test_only_the_points_and_the_configured_tiebreakers_tell_two_rows_apart(): void
    {
        $tournament = $this->finishedTournament();
        $a = $this->player($tournament, 1, 6.0);
        $b = $this->player($tournament, 2, 6.0);
        // Level on what the table orders by; the columns that are only shown differ
        StandingModel::query()->where('participant_id', $a->id)->update(['calculated_tiebreakers' => ['buchholz' => 4.0], 'median_buchholz' => 2.0]);
        StandingModel::query()->where('participant_id', $b->id)->update(['calculated_tiebreakers' => ['buchholz' => 4.0], 'median_buchholz' => 3.5]);

        $placements = [];
        foreach ($this->activitySource()->facts($tournament->id) as $fact) {
            $placements[] = $fact->placement;
        }

        $this->assertSame([1, 1], $placements);
    }

    public function test_a_configured_tiebreaker_that_differs_is_not_a_tie(): void
    {
        $tournament = $this->finishedTournament();
        $a = $this->player($tournament, 1, 6.0);
        $b = $this->player($tournament, 2, 6.0);
        StandingModel::query()->where('participant_id', $a->id)->update(['calculated_tiebreakers' => ['buchholz' => 5.0]]);
        StandingModel::query()->where('participant_id', $b->id)->update(['calculated_tiebreakers' => ['buchholz' => 4.0]]);

        $placements = [];
        foreach ($this->activitySource()->facts($tournament->id) as $fact) {
            $placements[] = $fact->placement;
        }

        $this->assertSame([1, 2], $placements);
    }

    public function test_a_finished_tournament_without_an_end_date_reports_nothing(): void
    {
        $tournament = $this->finishedTournament();
        $this->player($tournament, 1, 3.0);
        TournamentModel::query()->whereKey($tournament->id)->update(['completed_at' => null]);

        $this->assertSame([], $this->activitySource()->facts($tournament->id));
    }

    public function test_somebody_who_never_played_a_match_is_not_reported(): void
    {
        $tournament = $this->finishedTournament();
        $played = $this->player($tournament, 1, 3.0);
        // Enrolled and listed in the standings, but in no match
        $this->enroll($tournament, UserModel::factory()->create(), ParticipantStatus::Confirmed, 2, 0.0);

        $facts = $this->activitySource()->facts($tournament->id);

        $this->assertCount(1, $facts);
        $this->assertSame($played->user_id, $facts[0]->userId);
    }

    public function test_a_tournament_nobody_played_counts_for_nothing(): void
    {
        $tournament = $this->finishedTournament();

        $this->assertSame([], $this->activitySource()->facts($tournament->id));
    }

    public function test_only_finished_tournaments_of_a_published_event_count(): void
    {
        foreach ([TournamentStatus::Draft, TournamentStatus::RegistrationOpen, TournamentStatus::InProgress, TournamentStatus::Cancelled] as $status) {
            $tournament = $this->tournament($status, ['completed_at' => now()->subDay()]);
            $this->player($tournament, 1, 3.0);

            $this->assertSame([], $this->activitySource()->facts($tournament->id), $status->value);
        }

        $hidden = $this->finishedTournament(event: EventModel::factory()->draft()->create());
        $this->player($hidden, 1, 3.0);

        $this->assertSame([], $this->activitySource()->facts($hidden->id));
    }

    public function test_the_answer_does_not_depend_on_who_is_signed_in(): void
    {
        $subject = $this->createCountedActivitySubject();
        $guestView = $this->activitySource()->facts($subject);

        $this->actingAs(UserModel::factory()->create());

        $this->assertEquals($guestView, $this->activitySource()->facts($subject));
    }

    public function test_history_lists_what_counts_in_pages_with_a_stable_cursor(): void
    {
        $ids = [];
        foreach (range(1, 3) as $ignored) {
            $tournament = $this->finishedTournament();
            $this->player($tournament, 1, 3.0);
            $ids[] = $tournament->id;
        }
        // Neither of these counts
        $this->player($this->tournament(TournamentStatus::InProgress), 1, 3.0);
        $this->finishedTournament();
        sort($ids);

        $source = $this->activitySource();
        $first = $source->history(null, 2);
        $second = $source->history($first->nextCursor, 2);

        $this->assertCount(2, $first->subjects);
        $this->assertNotNull($first->nextCursor);
        $this->assertCount(1, $second->subjects);
        $this->assertNull($second->nextCursor);
        $this->assertSame($ids, array_map(static fn ($subject): string => $subject->subjectId, [...$first->subjects, ...$second->subjects]));

        foreach ([...$first->subjects, ...$second->subjects] as $subject) {
            $this->assertEquals($source->facts($subject->subjectId), $subject->facts);
        }
    }

    public function test_history_throws_on_a_cursor_it_cannot_read(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->activitySource()->history('not-a-cursor', 10);
    }

    public function test_history_throws_on_a_cursor_of_another_source(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->activitySource()->history(rtrim(strtr(base64_encode('gt1|session|'.fake()->uuid()), '+/', '-_'), '='), 10);
    }

    public function test_history_is_read_through_the_core(): void
    {
        $subject = $this->createCountedActivitySubject();

        $page = $this->app->make(ActivityReaderInterface::class)->history('tournaments.tournament', null, 10);

        $this->assertTrue($page->isRead());
        $this->assertSame([$subject], array_map(static fn ($s): string => $s->subjectId, $page->page->subjects ?? []));
    }

    public function test_describe_gives_title_and_path_to_whoever_may_see_the_tournament(): void
    {
        $shown = $this->finishedTournament(['name' => 'Gran torneo']);
        $this->player($shown, 1, 3.0);
        $hidden = $this->finishedTournament(event: EventModel::factory()->draft()->create());
        $draft = $this->tournament(TournamentStatus::Draft);

        foreach ([null, UserModel::factory()->create()] as $viewer) {
            $described = $this->activitySource()->describe([$shown->id, $hidden->id, $draft->id, 'no-uuid'], $viewer);

            $this->assertSame([$shown->id], array_keys($described));
            $this->assertSame('Gran torneo', $described[$shown->id]->title);
            $this->assertSame('/torneos/'.$shown->slug, $described[$shown->id]->url);
        }
    }

    public function test_the_game_profiles_are_the_values_of_its_attribute(): void
    {
        $this->gameProfile('Bolt Action');
        $this->gameProfile('Age of Sigmar');
        $source = $this->app->make(TournamentActivitySource::class);

        $this->assertSame(['tournament_profile'], array_map(static fn ($attribute): string => $attribute->key, $source->attributes()));
        $this->assertSame(
            ['Age of Sigmar', 'Bolt Action'],
            array_map(static fn ($value): string => $value->label, array_values(array_filter($source->attributeValues('tournament_profile'), static fn ($value): bool => in_array($value->label, ['Age of Sigmar', 'Bolt Action'], true)))),
        );
        $this->assertSame([], $source->attributeValues('unknown'));
    }
}
