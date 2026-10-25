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
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The tournament page links to the check-in only for a signed-in registered participant who can
 * check in right now: the same rule the check-in itself applies.
 */
final class TournamentShowCheckInTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_a_registered_participant_sees_the_check_in_while_the_window_is_open(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $this->registration($tournament, $user, ParticipantStatus::Registered);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', true));
    }

    public function test_the_check_in_is_hidden_before_the_window_opens(): void
    {
        $tournament = $this->tournament(startsInMinutes: 120);
        $user = UserModel::factory()->create();
        $this->registration($tournament, $user, ParticipantStatus::Registered);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
    }

    public function test_the_check_in_is_hidden_after_the_event_started(): void
    {
        $tournament = $this->tournament(startsInMinutes: -5);
        $user = UserModel::factory()->create();
        $this->registration($tournament, $user, ParticipantStatus::Registered);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
    }

    public function test_the_check_in_is_hidden_when_the_user_is_not_registered(): void
    {
        $tournament = $this->tournament();

        $this->actingAs(UserModel::factory()->create())
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
    }

    public function test_the_check_in_is_hidden_for_a_visitor(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $this->registration($tournament, $user, ParticipantStatus::Confirmed);

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
    }

    public function test_the_check_in_is_hidden_once_checked_in(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $this->registration($tournament, $user, ParticipantStatus::CheckedIn);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
    }

    public function test_the_check_in_waits_for_the_confirmation_when_the_organisation_confirms_by_hand(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $user = UserModel::factory()->create();
        $registration = $this->registration($tournament, $user, ParticipantStatus::Registered);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));

        $registration->update(['status' => ParticipantStatus::Confirmed->value]);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', true));
    }

    public function test_the_check_in_is_hidden_when_self_check_in_is_off(): void
    {
        $tournament = $this->tournament(['self_check_in_allowed' => false]);
        $user = UserModel::factory()->create();
        $this->registration($tournament, $user, ParticipantStatus::Registered);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
    }

    public function test_the_check_in_is_hidden_when_the_tournament_does_not_use_it(): void
    {
        $tournament = $this->tournament(['requires_check_in' => false]);
        $user = UserModel::factory()->create();
        $this->registration($tournament, $user, ParticipantStatus::Registered);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertInertia(fn (Assert $page) => $page->where('canCheckIn', false));
    }

    private function registration(TournamentModel $tournament, UserModel $user, ParticipantStatus $status): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => $status->value,
            'registered_at' => now()->subDay(),
            'checked_in_at' => $status === ParticipantStatus::CheckedIn ? now()->subMinute() : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = [], int $startsInMinutes = 10): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addMinutes($startsInMinutes)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de check-in',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationClosed,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
            ...$attributes,
        ]);
    }
}
