<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Registering and withdrawing from the web close when the tournament's event starts, whatever
 * the tournament status says, and registering only happens inside the configured dates.
 */
final class RegistrationClosesAtStartTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_user_cannot_register_once_the_event_has_started(): void
    {
        $tournament = $this->tournament(now()->subMinute());

        $this->actingAs(UserModel::factory()->create())
            ->json('POST', $this->registrationUrl($tournament), [])
            ->assertStatus(400)
            ->assertJson(['message' => 'El torneo ya ha comenzado: la inscripción está cerrada']);

        $this->assertDatabaseMissing('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    public function test_a_guest_cannot_register_once_the_event_has_started(): void
    {
        $tournament = $this->tournament(now()->subMinute());

        $this->json('POST', $this->registrationUrl($tournament), [
            'guest_name' => 'Invitado',
            'guest_email' => 'guest@example.com',
            'gdpr_consent' => true,
        ])
            ->assertStatus(400)
            ->assertJson(['message' => 'El torneo ya ha comenzado: la inscripción está cerrada']);

        $this->assertDatabaseMissing('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    public function test_a_user_cannot_register_before_the_opening_date(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00', ['registration_opens_at' => now()->addDay()]);

        $this->actingAs(UserModel::factory()->create())
            ->json('POST', $this->registrationUrl($tournament), [])
            ->assertStatus(400)
            ->assertJson(['message' => 'El torneo no está abierto para inscripción']);
    }

    public function test_a_user_cannot_register_after_the_closing_date(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00', ['registration_closes_at' => now()->subMinute()]);

        $this->actingAs(UserModel::factory()->create())
            ->json('POST', $this->registrationUrl($tournament), [])
            ->assertStatus(400)
            ->assertJson(['message' => 'El torneo no está abierto para inscripción']);
    }

    public function test_a_user_cannot_withdraw_once_the_event_has_started(): void
    {
        $tournament = $this->tournament(now()->subMinute());
        $user = UserModel::factory()->create();
        $participant = $this->register($tournament, $user);

        $this->actingAs($user)
            ->json('DELETE', $this->registrationUrl($tournament), [])
            ->assertStatus(400)
            ->assertJson(['message' => 'El torneo ya ha comenzado: ya no puedes retirarte']);

        $this->assertSame(ParticipantStatus::Registered, $participant->fresh()?->status);
    }

    public function test_a_user_can_withdraw_after_registration_closes_until_the_event_starts(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00', ['status' => TournamentStatus::RegistrationClosed]);
        $user = UserModel::factory()->create();
        $participant = $this->register($tournament, $user);

        $this->actingAs($user)
            ->json('DELETE', $this->registrationUrl($tournament), [])
            ->assertOk();

        $this->assertSame(ParticipantStatus::Withdrawn, $participant->fresh()?->status);
    }

    public function test_the_tournament_page_offers_neither_registering_nor_withdrawing_once_the_event_has_started(): void
    {
        $tournament = $this->tournament(now()->subMinute());
        $user = UserModel::factory()->create();
        $this->register($tournament, $user);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/Show', false)
                ->where('tournament.is_registration_open', false)
                ->where('tournament.can_withdraw', false)
                ->where('canRegister', false));
    }

    public function test_the_tournament_page_offers_withdrawing_after_registration_closes(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00', ['status' => TournamentStatus::RegistrationClosed]);
        $user = UserModel::factory()->create();
        $this->register($tournament, $user);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.is_registration_open', false)
                ->where('tournament.can_withdraw', true));
    }

    public function test_the_event_page_section_gets_the_same_flags(): void
    {
        $tournament = $this->tournament(now()->subMinute());
        $event = EventModel::query()->findOrFail($tournament->event_id);

        $this->get(route('events.show', $event->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.id', $tournament->id)
                ->where('tournament.is_registration_open', false)
                ->where('tournament.can_withdraw', false)
                ->where('canRegister', false));
    }

    public function test_the_new_error_messages_exist_in_the_spanish_translations(): void
    {
        $errors = require dirname(__DIR__, 4).'/lang/es/messages.php';

        $this->assertSame('El torneo ya ha comenzado: la inscripción está cerrada', $errors['errors']['tournament_started']);
        $this->assertSame('El torneo ya ha comenzado: ya no puedes retirarte', $errors['errors']['cannot_withdraw_started']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(DateTimeInterface|string $eventStartsAt, array $attributes = []): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => $eventStartsAt]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo ' . Str::random(8),
            'slug' => 'torneo-' . Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            ...$attributes,
        ]);
    }

    private function register(TournamentModel $tournament, UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now()->subDay(),
        ]);
    }

    private function registrationUrl(TournamentModel $tournament): string
    {
        return "/torneos/{$tournament->id}/inscripcion";
    }
}
