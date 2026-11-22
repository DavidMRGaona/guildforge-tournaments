<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Activity;

use App\Application\Activity\Contracts\ActivitySourceInterface;
use App\Application\Activity\Contracts\OffersActivityAttributes;
use App\Application\Activity\DTOs\ActivityFactDTO;
use App\Application\Activity\Services\ActivityReaderInterface;
use App\Domain\Activity\ActivityKind;
use App\Domain\Activity\ActivityRole;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use InvalidArgumentException;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Activity\TournamentActivitySource;
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
        $this->organize($tournament, UserModel::factory()->create());

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

    public function test_players_get_their_placement_and_the_organization_is_the_organizer(): void
    {
        $profile = $this->gameProfile('Warhammer');
        $tournament = $this->finishedTournament(['game_profile_id' => $profile->id]);
        $winner = UserModel::factory()->create();
        $second = UserModel::factory()->create();
        $organizer = UserModel::factory()->create();
        $this->player($tournament, 1, 9.0, user: $winner);
        $this->player($tournament, 2, 6.0, user: $second);
        $this->organize($tournament, $organizer);

        $facts = $this->factsByKey($tournament->id);

        $this->assertCount(3, $facts);
        $this->assertSame(1, $facts["{$winner->id}|player"]->placement);
        $this->assertSame(2, $facts["{$second->id}|player"]->placement);
        $this->assertNull($facts["{$organizer->id}|organizer"]->placement);

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

    public function test_withdrawn_and_disqualified_players_are_not_reported_even_when_they_played(): void
    {
        $tournament = $this->finishedTournament();
        $disqualified = $this->player($tournament, 1, 9.0, ParticipantStatus::Disqualified);
        $withdrawn = $this->player($tournament, 2, 6.0, ParticipantStatus::Withdrawn);
        $kept = $this->player($tournament, 3, 3.0);

        $facts = $this->activitySource()->facts($tournament->id);

        $this->assertCount(1, $facts);
        $this->assertSame($kept->user_id, $facts[0]->userId);
        // Those left out do not hold a place: the one who finished is the winner
        $this->assertSame(1, $facts[0]->placement);
        $this->assertNotSame($disqualified->user_id, $facts[0]->userId);
        $this->assertNotSame($withdrawn->user_id, $facts[0]->userId);
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

    public function test_a_tournament_nobody_played_counts_for_nothing_not_even_the_organizer(): void
    {
        $tournament = $this->finishedTournament();
        $this->organize($tournament, UserModel::factory()->create());

        $this->assertSame([], $this->activitySource()->facts($tournament->id));
    }

    public function test_an_organizer_who_also_played_has_a_fact_for_each_role(): void
    {
        $tournament = $this->finishedTournament();
        $both = UserModel::factory()->create();
        $this->player($tournament, 1, 3.0, user: $both);
        $this->organize($tournament, $both);

        $facts = $this->factsByKey($tournament->id);

        $this->assertSame(['player', 'organizer'], array_map(static fn (ActivityFactDTO $fact): string => $fact->role->value, array_values($facts)));
        $this->assertSame(ActivityRole::Organizer, $facts["{$both->id}|organizer"]->role);
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
