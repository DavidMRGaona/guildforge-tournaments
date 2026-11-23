<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Activity;

use App\Application\Activity\Services\ActivityPublisherInterface;
use App\Domain\Activity\Events\ActivityRecorded;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Application\Services\TournamentLifecycleServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\BuildsFinishedTournaments;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The module tells the core (core#774) whenever the facts of a finished tournament may have
 * changed, whatever made the change, so whoever keeps a record of them reads them again. Changes
 * to tournaments that are not finished say nothing: they have no facts.
 */
final class TournamentActivityPublishingTest extends ModuleTestCase
{
    use BuildsFinishedTournaments;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string TYPE = 'tournaments.tournament';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ActivityRecorded::class]);
        // The publisher is scoped and keeps the dispatcher it was built with
        $this->app->forgetInstance(ActivityPublisherInterface::class);
    }

    private function assertRecorded(TournamentModel $tournament, int $times = 1): void
    {
        $this->assertSame($times, $this->recorded()->count());
        Event::assertDispatched(ActivityRecorded::class, fn (ActivityRecorded $event): bool => $event->sourceType === self::TYPE && $event->subjectId === $tournament->id);
    }

    /**
     * What this module published: the core publishes its own sources too (accounts, core#777).
     *
     * @return Collection<int, ActivityRecorded>
     */
    private function recorded(): Collection
    {
        $events = Event::getFacadeRoot();
        $this->assertInstanceOf(EventFake::class, $events);

        return $events->dispatched(ActivityRecorded::class, fn (ActivityRecorded $event): bool => $event->sourceType === self::TYPE)
            ->map(fn (array $arguments): ActivityRecorded => $arguments[0])
            ->values();
    }

    private function eventOf(TournamentModel $tournament): EventModel
    {
        return EventModel::query()->findOrFail($tournament->event_id);
    }

    private function assertNothingRecorded(): void
    {
        $this->assertSame(0, $this->recorded()->count());
    }

    /**
     * A finished tournament with two players, as it is once everything is set; nothing recorded yet.
     *
     * @return array{TournamentModel, ParticipantModel, ParticipantModel}
     */
    private function settled(): array
    {
        $tournament = $this->finishedTournament();
        $first = $this->player($tournament, 1, 6.0);
        $second = $this->player($tournament, 2, 3.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        // Fresh copies, as a request would load them: the ones just created still say they were created
        return [
            TournamentModel::query()->findOrFail($tournament->id),
            ParticipantModel::query()->findOrFail($first->id),
            ParticipantModel::query()->findOrFail($second->id),
        ];
    }

    public function test_finishing_a_tournament_publishes_it_once_after_the_commit(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress, ['current_round' => 1]);
        $this->player($tournament, 1, 3.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        DB::transaction(function () use ($tournament): void {
            $this->app->make(TournamentLifecycleServiceInterface::class)->finish($tournament->id);

            $this->assertNothingRecorded();
        });

        $this->assertRecorded($tournament);
    }

    public function test_starting_a_tournament_publishes_nothing(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed);
        $this->player($tournament, 1, 0.0);
        $this->player($tournament, 2, 0.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $this->app->make(TournamentLifecycleServiceInterface::class)->start($tournament->id);

        $this->assertSame(TournamentStatus::InProgress, $tournament->refresh()->status);
        $this->assertNothingRecorded();
    }

    public function test_cancelling_a_tournament_that_is_not_finished_publishes_nothing(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed);
        $this->player($tournament, 1, 0.0);
        $this->player($tournament, 2, 0.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $this->app->make(TournamentLifecycleServiceInterface::class)->cancel($tournament->id);

        $this->assertNothingRecorded();
    }

    public function test_recalculating_the_standings_of_a_finished_tournament_publishes_it(): void
    {
        [$tournament] = $this->settled();

        $this->app->make(StandingsServiceInterface::class)->recalculate($tournament->id);

        $this->assertRecorded($tournament);
    }

    public function test_recalculating_the_standings_of_a_tournament_in_progress_publishes_nothing(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);
        $this->player($tournament, 1, 0.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $this->app->make(StandingsServiceInterface::class)->recalculate($tournament->id);

        $this->assertNothingRecorded();
    }

    public function test_correcting_a_result_after_the_end_publishes_the_tournament(): void
    {
        $tournament = $this->finishedTournament();
        $first = $this->player($tournament, 1, 3.0);
        $second = $this->player($tournament, 2, 0.0);
        $this->playMatch($tournament, $first, $second);
        $match = MatchModel::query()->whereNotNull('player_2_id')->firstOrFail();
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $this->app->make(MatchResultServiceInterface::class)->recordResult(new RecordMatchResultDTO(
            matchId: $match->id,
            result: MatchResult::PlayerTwoWin,
            reportedById: (string) UserModel::factory()->create()->id,
        ));

        $this->assertRecorded($tournament);
    }

    public function test_a_participant_who_leaves_or_is_disqualified_after_the_end_publishes_the_tournament(): void
    {
        foreach ([ParticipantStatus::Withdrawn, ParticipantStatus::Disqualified] as $status) {
            [$tournament, $first] = $this->settled();

            $first->update(['status' => $status->value]);

            $this->assertRecorded($tournament);
        }
    }

    public function test_a_participant_who_is_reinstated_or_moved_to_another_account_publishes_the_tournament(): void
    {
        [$tournament, , $second] = $this->settled();
        $second->update(['user_id' => UserModel::factory()->create()->id]);

        $this->assertRecorded($tournament);
    }

    public function test_a_participant_saved_without_changing_what_counts_publishes_nothing(): void
    {
        [, $first] = $this->settled();

        $first->update(['reminder_sent_at' => now()]);

        $this->assertNothingRecorded();
    }

    public function test_a_participant_removed_after_the_end_publishes_the_tournament(): void
    {
        [$tournament, $first] = $this->settled();

        $this->app->make(ParticipantRepositoryInterface::class)->delete(ParticipantId::fromString($first->id));

        $this->assertRecorded($tournament);
    }

    public function test_participants_of_a_tournament_not_finished_publish_nothing(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);
        $participant = $this->player($tournament, 1, 0.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $participant->update(['status' => ParticipantStatus::Withdrawn->value]);

        $this->assertNothingRecorded();
    }

    public function test_hiding_cancelling_or_reopening_a_finished_tournament_publishes_it(): void
    {
        foreach ([TournamentStatus::Draft, TournamentStatus::Cancelled, TournamentStatus::InProgress] as $status) {
            [$tournament] = $this->settled();

            $tournament->update(['status' => $status->value]);

            $this->assertRecorded($tournament);
        }
    }

    public function test_changing_its_game_profile_or_end_publishes_the_tournament(): void
    {
        [$tournament] = $this->settled();
        $tournament->update(['game_profile_id' => $this->gameProfile()->id]);
        $this->assertRecorded($tournament);

        [$other] = $this->settled();
        $other->update(['completed_at' => now()]);
        $this->assertRecorded($other);
    }

    public function test_changes_that_the_facts_do_not_carry_publish_nothing(): void
    {
        [$tournament] = $this->settled();

        $tournament->update(['name' => 'Otro nombre', 'description' => 'Otra descripción']);

        $this->assertNothingRecorded();
    }

    public function test_deleting_a_finished_tournament_publishes_it_and_deleting_one_in_progress_does_not(): void
    {
        [$tournament] = $this->settled();
        $tournament->delete();
        $this->assertRecorded($tournament);

        $running = $this->tournament(TournamentStatus::InProgress);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);
        $running->delete();
        $this->assertNothingRecorded();
    }

    public function test_unpublishing_or_deleting_the_event_publishes_its_finished_tournament(): void
    {
        [$tournament] = $this->settled();
        $this->eventOf($tournament)->update(['is_published' => false]);
        $this->assertRecorded($tournament);

        [$other] = $this->settled();
        $this->eventOf($other)->delete();
        $this->assertRecorded($other);
    }

    public function test_an_event_changed_in_other_ways_publishes_nothing(): void
    {
        [$tournament] = $this->settled();

        $this->eventOf($tournament)->update(['title' => 'Otro título']);

        $this->assertNothingRecorded();
    }

    public function test_renaming_or_deleting_the_game_profile_publishes_the_tournaments_that_carry_it(): void
    {
        $profile = $this->gameProfile('Antes');
        $tournament = $this->finishedTournament(['game_profile_id' => $profile->id]);
        $this->player($tournament, 1, 3.0);
        $unrelated = $this->finishedTournament();
        $this->player($unrelated, 1, 3.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $profile->update(['description' => 'Solo la descripción']);
        $this->assertNothingRecorded();

        $profile->update(['name' => 'Después']);
        $this->assertRecorded($tournament);

        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);
        $profile->delete();
        $this->assertRecorded($tournament);
    }

    public function test_deleting_the_game_profile_through_its_repository_is_heard_too(): void
    {
        $profile = $this->gameProfile();
        $tournament = $this->finishedTournament(['game_profile_id' => $profile->id]);
        $this->player($tournament, 1, 3.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $this->app->make(GameProfileRepositoryInterface::class)->delete(GameProfileId::fromString($profile->id));

        $this->assertRecorded($tournament);
    }

    public function test_deleting_an_account_for_good_publishes_what_it_played(): void
    {
        $user = UserModel::factory()->create();
        $played = $this->finishedTournament();
        $this->player($played, 1, 3.0, user: $user);
        $another = $this->finishedTournament();
        $this->player($another, 1, 3.0, user: $user);
        $unrelated = $this->finishedTournament();
        $this->player($unrelated, 1, 3.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $user->forceDelete();

        $this->assertEqualsCanonicalizing([$played->id, $another->id], $this->recorded()->map(fn (ActivityRecorded $event): string => $event->subjectId)->all());
    }

    public function test_an_unpublished_event_does_not_stop_the_publishing(): void
    {
        $tournament = $this->finishedTournament(event: EventModel::factory()->draft()->create());
        $this->player($tournament, 1, 3.0);
        Event::fake([ActivityRecorded::class]);
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $this->eventOf($tournament)->update(['is_published' => true]);

        $this->assertRecorded($tournament);
    }
}
