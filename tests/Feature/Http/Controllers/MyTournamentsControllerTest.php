<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

final class MyTournamentsControllerTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';
    protected bool $autoEnableModule = true;

    public function test_guest_cannot_access_my_tournaments(): void
    {
        $response = $this->get('/torneos/mis-torneos');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_see_my_tournaments_page(): void
    {
        $user = UserModel::factory()->create();

        $response = $this->actingAs($user)->get('/torneos/mis-torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/MyTournaments', false)
                ->has('upcoming')
                ->has('inProgress')
                ->has('past')
        );
    }

    public function test_returns_empty_arrays_when_user_has_no_tournaments(): void
    {
        $user = UserModel::factory()->create();

        $response = $this->actingAs($user)->get('/torneos/mis-torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/MyTournaments', false)
                ->where('upcoming', [])
                ->where('inProgress', [])
                ->where('past', [])
        );
    }

    public function test_tournaments_are_grouped_by_status(): void
    {
        $user = UserModel::factory()->create();

        // Create tournaments in different states
        $upcomingTournament = $this->createTournamentWithStatus(TournamentStatus::RegistrationOpen);
        $inProgressTournament = $this->createTournamentWithStatus(TournamentStatus::InProgress);
        $finishedTournament = $this->createTournamentWithStatus(TournamentStatus::Finished);

        // Register user in all tournaments
        $this->registerUserInTournament($user, $upcomingTournament);
        $this->registerUserInTournament($user, $inProgressTournament);
        $this->registerUserInTournament($user, $finishedTournament);

        $response = $this->actingAs($user)->get('/torneos/mis-torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/MyTournaments', false)
                ->has('upcoming', 1)
                ->has('inProgress', 1)
                ->has('past', 1)
        );
    }

    public function test_excludes_draft_and_cancelled_tournaments(): void
    {
        $user = UserModel::factory()->create();

        $draftTournament = $this->createTournamentWithStatus(TournamentStatus::Draft);
        $cancelledTournament = $this->createTournamentWithStatus(TournamentStatus::Cancelled);

        $this->registerUserInTournament($user, $draftTournament);
        $this->registerUserInTournament($user, $cancelledTournament);

        $response = $this->actingAs($user)->get('/torneos/mis-torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/MyTournaments', false)
                ->where('upcoming', [])
                ->where('inProgress', [])
                ->where('past', [])
        );
    }

    public function test_excludes_withdrawn_and_disqualified_participations(): void
    {
        $user = UserModel::factory()->create();

        $tournament1 = $this->createTournamentWithStatus(TournamentStatus::RegistrationOpen);
        $tournament2 = $this->createTournamentWithStatus(TournamentStatus::RegistrationOpen);

        // Create withdrawn and disqualified participations
        $this->registerUserInTournament($user, $tournament1, ParticipantStatus::Withdrawn);
        $this->registerUserInTournament($user, $tournament2, ParticipantStatus::Disqualified);

        $response = $this->actingAs($user)->get('/torneos/mis-torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/MyTournaments', false)
                ->where('upcoming', [])
                ->where('inProgress', [])
                ->where('past', [])
        );
    }

    public function test_returns_tournament_data_with_expected_fields(): void
    {
        $user = UserModel::factory()->create();

        $event = EventModel::factory()->create([
            'title' => 'Test Event',
            'start_date' => now()->addWeek(),
        ]);

        $tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament-' . Str::uuid()->toString(),
            'status' => TournamentStatus::RegistrationOpen,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'registration_opens_at' => now()->subDay(),
        ]);

        $this->registerUserInTournament($user, $tournament);

        $response = $this->actingAs($user)->get('/torneos/mis-torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/MyTournaments', false)
                ->has('upcoming', 1)
                ->has('upcoming.0.id')
                ->has('upcoming.0.name')
                ->has('upcoming.0.slug')
                ->has('upcoming.0.status')
                ->has('upcoming.0.statusLabel')
                ->has('upcoming.0.startsAt')
                ->has('upcoming.0.eventName')
                ->has('upcoming.0.participantId')
                ->has('upcoming.0.participantStatus')
                ->has('upcoming.0.participantStatusLabel')
                ->has('upcoming.0.isUpcoming')
                ->has('upcoming.0.isInProgress')
                ->has('upcoming.0.isPast')
        );
    }

    public function test_different_user_cannot_see_other_user_tournaments(): void
    {
        $user1 = UserModel::factory()->create();
        $user2 = UserModel::factory()->create();

        $tournament = $this->createTournamentWithStatus(TournamentStatus::RegistrationOpen);
        $this->registerUserInTournament($user1, $tournament);

        // User 2 should not see user 1's tournament
        $response = $this->actingAs($user2)->get('/torneos/mis-torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/MyTournaments', false)
                ->where('upcoming', [])
                ->where('inProgress', [])
                ->where('past', [])
        );
    }

    private function createTournamentWithStatus(TournamentStatus $status): TournamentModel
    {
        $event = EventModel::factory()->create([
            'start_date' => match ($status) {
                TournamentStatus::Finished => now()->subWeek(),
                TournamentStatus::InProgress => now()->subDay(),
                default => now()->addWeek(),
            },
        ]);

        $data = [
            'event_id' => $event->id,
            'name' => 'Test Tournament ' . Str::random(8),
            'slug' => 'test-tournament-' . Str::uuid()->toString(),
            'status' => $status,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
        ];

        if ($status === TournamentStatus::InProgress) {
            $data['started_at'] = now()->subHour();
        }

        if ($status === TournamentStatus::Finished) {
            $data['started_at'] = now()->subDay();
            $data['completed_at'] = now()->subHour();
        }

        if (in_array($status, [TournamentStatus::RegistrationOpen, TournamentStatus::RegistrationClosed], true)) {
            $data['registration_opens_at'] = now()->subWeek();
        }

        return TournamentModel::create($data);
    }

    private function registerUserInTournament(
        UserModel $user,
        TournamentModel $tournament,
        ParticipantStatus $status = ParticipantStatus::Registered
    ): ParticipantModel {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => $status,
            'registered_at' => now(),
        ]);
    }
}
