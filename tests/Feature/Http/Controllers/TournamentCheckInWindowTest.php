<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The check-in window is computed from the event start the tournament entity carries.
 */
final class TournamentCheckInWindowTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_window_opens_before_the_event_start_and_exposes_it(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00');

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('eventStartDate', '2036-03-14T10:00:00+01:00')
                ->where('checkInWindow.status', 'not_yet')
                ->where('checkInWindow.opens_at', '2036-03-14T09:30:00+01:00')
                ->where('checkInWindow.closes_at', '2036-03-14T10:00:00+01:00'));
    }

    public function test_the_window_is_open_just_before_the_event_start(): void
    {
        $tournament = $this->tournament(now()->addMinutes(10));

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('checkInWindow.status', 'open'));
    }

    public function test_the_window_is_closed_once_the_event_has_started(): void
    {
        $tournament = $this->tournament(now()->subMinute());

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('checkInWindow.status', 'closed'));
    }

    private function tournament(\DateTimeInterface|string $eventStartsAt): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => $eventStartsAt]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de check-in',
            'slug' => 'torneo-check-in',
            'status' => TournamentStatus::RegistrationOpen,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
        ]);
    }
}
