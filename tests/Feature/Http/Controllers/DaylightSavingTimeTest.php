<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\FixedClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The event start, the registration and the check-in window on the two nights the clocks
 * change in Europe/Madrid. On 25 October 2026 the hour from 02:00 to 03:00 happens twice
 * (00:00-01:00 UTC as CEST, 01:00-02:00 UTC as CET); on 28 March 2027 it does not happen.
 */
final class DaylightSavingTimeTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_at_the_first_pass_of_the_repeated_hour_an_event_that_began_before_it_has_started(): void
    {
        $this->clockAt('2026-10-25T02:15:00+02:00');
        $tournament = $this->tournament('2026-10-25 01:30:00');

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.is_registration_open', false)
                ->where('tournament.can_withdraw', false));
    }

    public function test_at_the_first_pass_of_the_repeated_hour_an_event_later_that_night_is_still_open(): void
    {
        $this->clockAt('2026-10-25T02:15:00+02:00');
        $tournament = $this->tournament('2026-10-25 03:30:00');

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.is_registration_open', true)
                ->where('tournament.can_withdraw', true));

        $this->actingAs(UserModel::factory()->create())
            ->json('POST', "/torneos/{$tournament->id}/inscripcion", [])
            ->assertSuccessful();

        $this->assertDatabaseHas('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    public function test_at_the_first_pass_of_the_repeated_hour_the_check_in_opens_its_minutes_before_the_start(): void
    {
        // 03:30 CET is 02:30 UTC, so a 30-minute window opens at 02:00 UTC: 03:00 CET
        $this->clockAt('2026-10-25T02:15:00+02:00');
        $tournament = $this->tournament('2026-10-25 03:30:00');

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkInWindow.status', 'not_yet')
                ->where('checkInWindow.opens_at', '2026-10-25T03:00:00+01:00')
                ->where('checkInWindow.closes_at', '2026-10-25T03:30:00+01:00'));
    }

    public function test_at_the_second_pass_of_the_repeated_hour_a_guest_checks_in_inside_the_window(): void
    {
        // 03:10 CET is 02:10 UTC; an hour before is 01:10 UTC, five minutes before this clock
        $this->clockAt('2026-10-25T02:15:00+01:00');
        $tournament = $this->tournament('2026-10-25 03:10:00', checkInMinutesBefore: 60);
        $participant = $this->confirmedGuest($tournament);

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkInWindow.status', 'open')
                ->where('checkInWindow.opens_at', '2026-10-25T02:10:00+01:00'));

        $this->postJson(route('tournaments.check-in.store', $tournament->slug), [
            'email' => 'ana@example.com',
            'gdpr_consent' => true,
        ])->assertOk();

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    public function test_on_the_night_the_clocks_go_forward_the_check_in_opens_before_the_start(): void
    {
        // 03:10 CEST is 01:10 UTC; thirty minutes before is 00:40 UTC (01:40 CET), not 03:40
        $this->clockAt('2027-03-28T01:50:00+01:00');
        $tournament = $this->tournament('2027-03-28 03:10:00');
        $participant = $this->confirmedGuest($tournament);

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('checkInWindow.status', 'open')
                ->where('checkInWindow.opens_at', '2027-03-28T01:40:00+01:00')
                ->where('checkInWindow.closes_at', '2027-03-28T03:10:00+02:00'));

        $this->postJson(route('tournaments.check-in.store', $tournament->slug), [
            'email' => 'ana@example.com',
            'gdpr_consent' => true,
        ])->assertOk();

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    public function test_a_start_stored_inside_the_repeated_hour_reads_as_its_second_pass(): void
    {
        // Known limitation: dates are stored as Europe/Madrid wall times, so 02:30 that night
        // cannot tell CEST from CET and PHP reads the second pass (CET). It goes once the core
        // stores dates in UTC.
        $this->clockAt('2026-10-25T01:00:00+02:00');
        $tournament = $this->tournament('2026-10-25 02:30:00');

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('eventStartDate', '2026-10-25T02:30:00+01:00'));
    }

    private function clockAt(string $instant): void
    {
        $this->app->instance(Clock::class, FixedClock::at($instant));
    }

    private function tournament(string $eventStartsAt, int $checkInMinutesBefore = 30): TournamentModel
    {
        // app.timezone is Europe/Madrid, so the start is a venue local time
        $event = EventModel::factory()->published()->create([
            'start_date' => $eventStartsAt,
            'end_date' => '2027-12-31 23:00:00',
        ]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo nocturno',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => $checkInMinutesBefore,
        ]);
    }

    private function confirmedGuest(TournamentModel $tournament): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => ParticipantStatus::Confirmed->value,
            'registered_at' => '2026-10-20 18:00:00',
            'email_confirmed_at' => '2026-10-20 18:00:00',
        ]);
    }
}
