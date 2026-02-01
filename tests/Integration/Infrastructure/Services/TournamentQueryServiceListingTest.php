<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\TestCase;

final class TournamentQueryServiceListingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private TournamentQueryServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TournamentQueryServiceInterface::class);
    }

    public function test_get_published_paginated_returns_only_visible_tournaments(): void
    {
        $this->createTournamentWithStatus('registration_open');
        $this->createTournamentWithStatus('registration_closed');
        $this->createTournamentWithStatus('in_progress');
        $this->createTournamentWithStatus('finished');
        $this->createTournamentWithStatus('draft'); // Should not appear
        $this->createTournamentWithStatus('cancelled'); // Should not appear

        $result = $this->service->getPublishedPaginated(1, 12);

        $this->assertCount(4, $result);
        foreach ($result as $tournament) {
            $this->assertInstanceOf(TournamentResponseDTO::class, $tournament);
            $this->assertNotEquals(TournamentStatus::Draft, $tournament->status);
            $this->assertNotEquals(TournamentStatus::Cancelled, $tournament->status);
        }
    }

    public function test_get_published_paginated_respects_page_and_per_page(): void
    {
        // Create 15 visible tournaments
        for ($i = 0; $i < 15; $i++) {
            $this->createTournamentWithStatus('registration_open', "Tournament {$i}");
        }

        $page1 = $this->service->getPublishedPaginated(1, 10);
        $page2 = $this->service->getPublishedPaginated(2, 10);

        $this->assertCount(10, $page1);
        $this->assertCount(5, $page2);
    }

    public function test_get_published_paginated_orders_in_progress_first(): void
    {
        // Create in reverse expected order
        $this->createTournamentWithStatus('finished', 'Finished Tournament');
        sleep(1); // Ensure different timestamps
        $this->createTournamentWithStatus('registration_open', 'Upcoming Tournament');
        sleep(1);
        $this->createTournamentWithStatus('in_progress', 'In Progress Tournament');

        $result = $this->service->getPublishedPaginated(1, 12);

        $this->assertCount(3, $result);
        $this->assertEquals('In Progress Tournament', $result[0]->name);
    }

    public function test_get_published_paginated_orders_upcoming_after_in_progress(): void
    {
        $this->createTournamentWithStatus('registration_open', 'Upcoming Tournament');
        $this->createTournamentWithStatus('in_progress', 'In Progress Tournament');

        $result = $this->service->getPublishedPaginated(1, 12);

        $this->assertCount(2, $result);
        $this->assertEquals('In Progress Tournament', $result[0]->name);
        $this->assertEquals('Upcoming Tournament', $result[1]->name);
    }

    public function test_get_published_paginated_orders_finished_last(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming');
        $this->createTournamentWithStatus('finished', 'Finished');

        $result = $this->service->getPublishedPaginated(1, 12);

        $this->assertCount(3, $result);
        $this->assertEquals('Finished', $result[2]->name);
    }

    public function test_get_published_paginated_filters_by_status(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming');
        $this->createTournamentWithStatus('finished', 'Finished');

        // Filter by in_progress
        $result = $this->service->getPublishedPaginated(1, 12, [TournamentStatus::InProgress->value]);

        $this->assertCount(1, $result);
        $this->assertEquals('In Progress', $result[0]->name);
    }

    public function test_get_published_paginated_filters_by_multiple_statuses(): void
    {
        $this->createTournamentWithStatus('in_progress', 'In Progress');
        $this->createTournamentWithStatus('registration_open', 'Upcoming Open');
        $this->createTournamentWithStatus('registration_closed', 'Upcoming Closed');
        $this->createTournamentWithStatus('finished', 'Finished');

        // Filter by registration_open and registration_closed
        $result = $this->service->getPublishedPaginated(1, 12, [
            TournamentStatus::RegistrationOpen->value,
            TournamentStatus::RegistrationClosed->value,
        ]);

        $this->assertCount(2, $result);
    }

    public function test_get_published_total_returns_correct_count(): void
    {
        $this->createTournamentWithStatus('registration_open');
        $this->createTournamentWithStatus('registration_closed');
        $this->createTournamentWithStatus('in_progress');
        $this->createTournamentWithStatus('finished');
        $this->createTournamentWithStatus('draft'); // Should not count
        $this->createTournamentWithStatus('cancelled'); // Should not count

        $total = $this->service->getPublishedTotal();

        $this->assertEquals(4, $total);
    }

    public function test_get_published_total_with_status_filter(): void
    {
        $this->createTournamentWithStatus('in_progress');
        $this->createTournamentWithStatus('registration_open');
        $this->createTournamentWithStatus('finished');

        $total = $this->service->getPublishedTotal([TournamentStatus::InProgress->value]);

        $this->assertEquals(1, $total);
    }

    public function test_get_published_paginated_returns_empty_for_empty_database(): void
    {
        $result = $this->service->getPublishedPaginated(1, 12);

        $this->assertCount(0, $result);
    }

    public function test_get_published_total_returns_zero_for_empty_database(): void
    {
        $total = $this->service->getPublishedTotal();

        $this->assertEquals(0, $total);
    }

    public function test_get_published_paginated_includes_participant_count(): void
    {
        $tournament = $this->createTournamentWithStatus('registration_open', 'Test Tournament');

        // Add some participants (we'd need to create them properly)
        // For now, just verify the count field exists
        $result = $this->service->getPublishedPaginated(1, 12);

        $this->assertCount(1, $result);
        $this->assertIsInt($result[0]->participantCount);
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
