<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeInterface;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The check-in page posts with fetch and reads JSON: every outcome answers JSON with its own
 * status and message, never a redirect that fetch would follow. Inertia visits keep the redirects.
 */
final class CheckInJsonResponseTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_a_guest_check_in_answers_200_with_the_success_message(): void
    {
        $tournament = $this->tournament();
        $participant = $this->guest($tournament, ['status' => ParticipantStatus::Confirmed->value]);

        $this->postJson($this->checkInUrl($tournament), $this->guestPayload())
            ->assertOk()
            ->assertExactJson(['message' => '¡Check-in realizado!']);

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    public function test_a_signed_in_check_in_answers_200_with_the_success_message(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $participant = $this->userRow($tournament, $user, ParticipantStatus::Confirmed);

        $this->actingAs($user)
            ->postJson($this->checkInUrl($tournament))
            ->assertOk()
            ->assertExactJson(['message' => '¡Check-in realizado!']);

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    public function test_an_unknown_email_answers_422_on_the_email_field(): void
    {
        $tournament = $this->tournament();

        $this->postJson($this->checkInUrl($tournament), $this->guestPayload())
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'No encontramos inscripción con ese email',
                'errors' => ['email' => ['No encontramos inscripción con ese email']],
            ]);
    }

    public function test_a_signed_in_user_without_a_registration_answers_422(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();

        $this->actingAs($user)
            ->postJson($this->checkInUrl($tournament))
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'No estás inscrito en este torneo',
                'errors' => ['check_in' => ['No estás inscrito en este torneo']],
            ]);
    }

    public function test_an_unconfirmed_email_answers_422_on_the_email_field(): void
    {
        $tournament = $this->tournament();
        $participant = $this->guest($tournament, [
            'status' => ParticipantStatus::Registered->value,
            'email_confirmed_at' => null,
        ]);

        $message = 'Confirma tu inscripción desde el enlace del email antes de hacer el check-in.';
        $this->postJson($this->checkInUrl($tournament), $this->guestPayload())
            ->assertStatus(422)
            ->assertExactJson(['message' => $message, 'errors' => ['email' => [$message]]]);

        $this->assertSame(ParticipantStatus::Registered, $participant->fresh()?->status);
    }

    public function test_a_registration_the_organisation_has_not_confirmed_answers_409(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $this->guest($tournament, ['status' => ParticipantStatus::Registered->value]);

        $message = 'La organización aún no ha confirmado tu inscripción: no puedes hacer el check-in todavía.';
        $this->postJson($this->checkInUrl($tournament), $this->guestPayload())
            ->assertStatus(409)
            ->assertExactJson(['message' => $message, 'errors' => ['check_in' => [$message]]]);
    }

    public function test_a_second_check_in_answers_409(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $this->userRow($tournament, $user, ParticipantStatus::CheckedIn);

        $this->actingAs($user)
            ->postJson($this->checkInUrl($tournament))
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Ya has hecho check-in',
                'errors' => ['check_in' => ['Ya has hecho check-in']],
            ]);
    }

    public function test_a_check_in_outside_the_window_answers_409(): void
    {
        // The window opens 30 minutes before the start: two hours ahead it is not open yet
        $tournament = $this->tournament([], now()->addHours(2));
        $this->guest($tournament, ['status' => ParticipantStatus::Confirmed->value]);

        $this->postJson($this->checkInUrl($tournament), $this->guestPayload())
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'La ventana de check-in está cerrada',
                'errors' => ['check_in' => ['La ventana de check-in está cerrada']],
            ]);
    }

    public function test_a_tournament_without_self_check_in_answers_403(): void
    {
        $tournament = $this->tournament(['self_check_in_allowed' => false]);
        $this->guest($tournament, ['status' => ParticipantStatus::Confirmed->value]);

        $this->postJson($this->checkInUrl($tournament), $this->guestPayload())
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'El check-in solo está disponible desde administración',
                'errors' => ['check_in' => ['El check-in solo está disponible desde administración']],
            ]);
    }

    public function test_an_inertia_refusal_still_goes_back_with_the_error(): void
    {
        $tournament = $this->tournament();

        $this->from(route('tournaments.check-in.show', $tournament->slug))
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post($this->checkInUrl($tournament), $this->guestPayload())
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasErrors(['email' => 'No encontramos inscripción con ese email']);
    }

    /**
     * @return array{email: string, gdpr_consent: bool}
     */
    private function guestPayload(): array
    {
        return ['email' => 'ana@example.com', 'gdpr_consent' => true];
    }

    private function checkInUrl(TournamentModel $tournament): string
    {
        return route('tournaments.check-in.store', ['slug' => $tournament->slug]);
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
            'email_confirmed_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    private function userRow(TournamentModel $tournament, UserModel $user, ParticipantStatus $status): ParticipantModel
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
    private function tournament(array $attributes = [], ?DateTimeInterface $startsAt = null): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => $startsAt ?? now()->addMinutes(10)]);

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
