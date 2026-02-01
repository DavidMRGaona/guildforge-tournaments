<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

final class TournamentListControllerTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';
    protected bool $autoEnableModule = true;

    public function test_index_displays_published_tournaments(): void
    {
        $this->createTournamentWithStatus('registration_open');
        $this->createTournamentWithStatus('registration_closed');
        $this->createTournamentWithStatus('in_progress');
        $this->createTournamentWithStatus('finished');
        // These should NOT appear
        $this->createTournamentWithStatus('draft');
        $this->createTournamentWithStatus('cancelled');

        $response = $this->get('/torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 4)
        );
    }

    public function test_index_excludes_draft_and_cancelled_tournaments(): void
    {
        $this->createTournamentWithStatus('draft');
        $this->createTournamentWithStatus('cancelled');

        $response = $this->get('/torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 0)
        );
    }

    public function test_index_paginates_tournaments(): void
    {
        // Create 15 visible tournaments
        for ($i = 0; $i < 15; $i++) {
            $this->createTournamentWithStatus('registration_open', "Tournament {$i}");
        }

        $response = $this->get('/torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 12)
                ->has('tournaments.meta.currentPage')
                ->has('tournaments.meta.lastPage')
                ->has('tournaments.meta.perPage')
                ->has('tournaments.meta.total')
                ->where('tournaments.meta.total', 15)
        );
    }

    public function test_index_orders_in_progress_first(): void
    {
        $upcoming = $this->createTournamentWithStatus('registration_open', 'Upcoming Tournament');
        $inProgress = $this->createTournamentWithStatus('in_progress', 'In Progress Tournament');
        $finished = $this->createTournamentWithStatus('finished', 'Finished Tournament');

        $response = $this->get('/torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 3)
                ->where('tournaments.data.0.name', 'In Progress Tournament')
        );
    }

    public function test_index_orders_upcoming_after_in_progress(): void
    {
        $upcoming = $this->createTournamentWithStatus('registration_open', 'Upcoming Tournament');
        $inProgress = $this->createTournamentWithStatus('in_progress', 'In Progress Tournament');

        $response = $this->get('/torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 2)
                ->where('tournaments.data.0.name', 'In Progress Tournament')
                ->where('tournaments.data.1.name', 'Upcoming Tournament')
        );
    }

    public function test_index_orders_finished_last(): void
    {
        $finished = $this->createTournamentWithStatus('finished', 'Finished Tournament');
        $upcoming = $this->createTournamentWithStatus('registration_open', 'Upcoming Tournament');
        $inProgress = $this->createTournamentWithStatus('in_progress', 'In Progress Tournament');

        $response = $this->get('/torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 3)
                ->where('tournaments.data.2.name', 'Finished Tournament')
        );
    }

    public function test_index_filters_by_active_status(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming');
        $this->createTournamentWithStatus('finished', 'Finished');

        $response = $this->get('/torneos?status=active');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 1)
                ->where('tournaments.data.0.name', 'In Progress')
        );
    }

    public function test_index_filters_by_upcoming_status(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming Open');
        $this->createTournamentWithStatus('registration_closed', 'Upcoming Closed');
        $this->createTournamentWithStatus('finished', 'Finished');

        $response = $this->get('/torneos?status=upcoming');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 2)
        );
    }

    public function test_index_filters_by_past_status(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming');
        $this->createTournamentWithStatus('finished', 'Finished');

        $response = $this->get('/torneos?status=past');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 1)
                ->where('tournaments.data.0.name', 'Finished')
        );
    }

    public function test_index_returns_all_with_status_all(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming');
        $this->createTournamentWithStatus('finished', 'Finished');

        $response = $this->get('/torneos?status=all');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 3)
        );
    }

    public function test_index_includes_current_status_filter(): void
    {
        $response = $this->get('/torneos?status=active');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->where('currentFilter', 'active')
        );
    }

    public function test_index_returns_tournaments_with_expected_fields(): void
    {
        $event = EventModel::factory()->create();
        TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'description' => 'A test tournament description',
            'status' => 'registration_open',
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'max_participants' => 16,
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addWeek(),
        ]);

        $response = $this->get('/torneos');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 1)
                ->has('tournaments.data.0.id')
                ->has('tournaments.data.0.name')
                ->has('tournaments.data.0.slug')
                ->has('tournaments.data.0.status')
                ->has('tournaments.data.0.statusLabel')
                ->has('tournaments.data.0.participantCount')
                ->has('tournaments.data.0.maxParticipants')
        );
    }

    public function test_index_ignores_invalid_status_filter(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming');

        $response = $this->get('/torneos?status=invalid');

        $response->assertStatus(200);
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('Tournaments/Index', false)
                ->has('tournaments.data', 2)
        );
    }

    private function createTournamentWithStatus(string $status, string $name = 'Test Tournament'): TournamentModel
    {
        $event = EventModel::factory()->create();

        $data = [
            'event_id' => $event->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . \Illuminate\Support\Str::uuid()->toString(),
            'status' => $status,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
        ];

        if ($status === 'in_progress') {
            $data['started_at'] = now()->subHour();
        }

        if ($status === 'finished') {
            $data['started_at'] = now()->subDay();
            $data['completed_at'] = now()->subHour();
        }

        if (in_array($status, ['registration_open', 'registration_closed'], true)) {
            $data['registration_opens_at'] = now()->subWeek();
        }

        return TournamentModel::create($data);
    }
}
