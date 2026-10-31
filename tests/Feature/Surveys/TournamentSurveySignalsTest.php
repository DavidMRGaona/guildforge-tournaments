<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Surveys;

use App\Domain\Surveys\Events\SurveySubjectCancelled;
use App\Domain\Surveys\Events\SurveySubjectEnded;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Application\Services\TournamentLifecycleServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\TournamentCancelled;
use Modules\Tournaments\Domain\Events\TournamentFinished;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Finishing or cancelling a tournament goes through the domain and tells a surveys module, if
 * any, through the core's events, once and after the change commits.
 */
final class TournamentSurveySignalsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentLifecycleServiceInterface $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TournamentFinished::class, TournamentCancelled::class, SurveySubjectEnded::class, SurveySubjectCancelled::class]);
        $this->lifecycle = $this->app->make(TournamentLifecycleServiceInterface::class);
    }

    public function test_finishing_announces_tournament_finished_and_the_survey_subject_end(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress, ['current_round' => 3]);
        $winner = $this->standing($tournament, 1);
        $this->standing($tournament, 2);

        $this->lifecycle->finish($tournament->id);

        $tournament->refresh();
        $this->assertSame(TournamentStatus::Finished, $tournament->status);
        $this->assertNotNull($tournament->completed_at);

        Event::assertDispatched(TournamentFinished::class, fn (TournamentFinished $event): bool => $event->tournamentId === $tournament->id
            && $event->roundsPlayed === 3
            && $event->winnerId === $winner->participant_id);
        Event::assertDispatched(SurveySubjectEnded::class, fn (SurveySubjectEnded $event): bool => $event->sourceType === 'tournament'
            && $event->subjectId === $tournament->id
            && $event->endedAt->getTimestamp() === $tournament->completed_at->getTimestamp());
    }

    public function test_without_standings_the_finish_has_no_winner(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);

        $this->lifecycle->finish($tournament->id);

        Event::assertDispatched(TournamentFinished::class, fn (TournamentFinished $event): bool => $event->winnerId === null);
        Event::assertDispatchedTimes(SurveySubjectEnded::class, 1);
    }

    public function test_the_end_is_announced_after_the_commit(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);

        DB::transaction(function () use ($tournament): void {
            $this->lifecycle->finish($tournament->id);

            Event::assertNotDispatched(SurveySubjectEnded::class);
            Event::assertNotDispatched(TournamentFinished::class);
        });

        Event::assertDispatchedTimes(SurveySubjectEnded::class, 1);
    }

    public function test_finishing_twice_announces_once(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);
        $this->lifecycle->finish($tournament->id);

        try {
            $this->lifecycle->finish($tournament->id);
            $this->fail('A finished tournament cannot finish again.');
        } catch (InvalidStateTransitionException) {
            // Expected: the second click finds it already finished
        }

        Event::assertDispatchedTimes(TournamentFinished::class, 1);
        Event::assertDispatchedTimes(SurveySubjectEnded::class, 1);
    }

    public function test_a_refused_transition_announces_nothing(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen);

        $this->expectException(InvalidStateTransitionException::class);

        try {
            $this->lifecycle->finish($tournament->id);
        } finally {
            $this->assertSame(TournamentStatus::RegistrationOpen, $tournament->refresh()->status);
            Event::assertNotDispatched(TournamentFinished::class);
            Event::assertNotDispatched(SurveySubjectEnded::class);
        }
    }

    public function test_cancelling_announces_the_survey_subject_cancellation(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen);

        $this->lifecycle->cancel($tournament->id);

        $this->assertSame(TournamentStatus::Cancelled, $tournament->refresh()->status);
        Event::assertDispatched(TournamentCancelled::class, fn (TournamentCancelled $event): bool => $event->tournamentId === $tournament->id
            && $event->previousStatus === TournamentStatus::RegistrationOpen);
        Event::assertDispatched(SurveySubjectCancelled::class, fn (SurveySubjectCancelled $event): bool => $event->sourceType === 'tournament'
            && $event->subjectId === $tournament->id);
    }

    public function test_a_finished_tournament_cannot_be_cancelled(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);

        try {
            $this->lifecycle->cancel($tournament->id);
            $this->fail('A finished tournament cannot be cancelled.');
        } catch (InvalidStateTransitionException) {
            // Expected
        }

        $this->assertSame(TournamentStatus::Finished, $tournament->refresh()->status);
        Event::assertNotDispatched(TournamentCancelled::class);
        Event::assertNotDispatched(SurveySubjectCancelled::class);
    }

    public function test_the_panel_finishes_and_cancels_through_the_service(): void
    {
        $this->registerOnAdminPanel([TournamentResource::class]);
        $this->actingAsPanelAdmin();
        $finished = $this->tournament(TournamentStatus::InProgress);
        $cancelled = $this->tournament(TournamentStatus::RegistrationClosed);

        Livewire::test(EditTournament::class, ['record' => $finished->getRouteKey()])->callAction('finishTournament')->assertHasNoActionErrors();
        Livewire::test(EditTournament::class, ['record' => $cancelled->getRouteKey()])->callAction('cancelTournament')->assertHasNoActionErrors();

        $this->assertSame(TournamentStatus::Finished, $finished->refresh()->status);
        $this->assertSame(TournamentStatus::Cancelled, $cancelled->refresh()->status);
        Event::assertDispatched(SurveySubjectEnded::class, fn (SurveySubjectEnded $event): bool => $event->subjectId === $finished->id);
        Event::assertDispatched(SurveySubjectCancelled::class, fn (SurveySubjectCancelled $event): bool => $event->subjectId === $cancelled->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(TournamentStatus $status, array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['is_published' => true])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => $status->value,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
            'started_at' => $status === TournamentStatus::InProgress ? now()->subHours(3) : null,
            ...$attributes,
        ])->refresh();
    }

    private function standing(TournamentModel $tournament, int $rank): StandingModel
    {
        $participant = ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => ParticipantStatus::CheckedIn->value,
            'registered_at' => now(),
        ]);

        return StandingModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'participant_id' => $participant->id,
            'rank' => $rank,
        ]);
    }
}
