<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The check-in window is computed from the event start the tournament entity carries.
 */
final class TournamentCheckInWindowTest extends ModuleTestCase
{
    use PinsTheClock;

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

    public function test_an_unconfirmed_guest_cannot_self_check_in(): void
    {
        $tournament = $this->tournament(now()->addMinutes(10));
        $participant = $this->guest($tournament, [
            'email_confirmed_at' => null,
            'confirmation_token' => 'pending-confirmation-token',
            'confirmation_mode' => GuestConfirmationMode::Hold->value,
            'confirmation_expires_at' => now()->addMinutes(10),
            'confirmation_sent_at' => now()->subHour(),
        ]);

        $this->from(route('tournaments.check-in.show', $tournament->slug))
            ->post(route('tournaments.check-in.store', $tournament->slug), [
                'email' => 'ana@example.com',
                'gdpr_consent' => true,
            ])
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasErrors([
                'email' => 'Confirma tu inscripción desde el enlace del email antes de hacer el check-in.',
            ]);

        $fresh = $participant->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(ParticipantStatus::Registered, $fresh->status);
        $this->assertNull($fresh->checked_in_at);
    }

    public function test_check_in_by_email_ignores_case(): void
    {
        $tournament = $this->tournament(now()->addMinutes(10));
        $participant = $this->guest($tournament, [
            'status' => ParticipantStatus::Confirmed->value,
            'email_confirmed_at' => now()->subDay(),
        ]);

        $this->post(route('tournaments.check-in.store', $tournament->slug), [
            'email' => 'Ana@Example.COM',
            'gdpr_consent' => true,
        ])
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasNoErrors();

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guest(TournamentModel $tournament, array $attributes): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => now()->subDay(),
            ...$attributes,
        ]);
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
