<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Self check-in only looks at active registrations, follows the panel's rule (with manual
 * confirmation only confirmed participants) and answers every refusal with a message, never a 500.
 */
final class SelfCheckInTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_an_expired_guest_row_does_not_hide_the_active_account_row_with_the_same_email(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create(['email' => 'ana@example.com']);
        $expired = $this->guest($tournament, ['status' => ParticipantStatus::Expired->value, 'email_confirmed_at' => null]);
        $active = $this->userRow($tournament, $user, ParticipantStatus::Confirmed);

        $this->checkInByEmail($tournament, 'ana@example.com')
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(ParticipantStatus::CheckedIn, $active->fresh()?->status);
        $this->assertSame(ParticipantStatus::Expired, $expired->fresh()?->status);
    }

    public function test_a_signed_in_user_checks_in_their_own_row_despite_an_expired_guest_row_with_their_email(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create(['email' => 'ana@example.com']);
        $this->guest($tournament, ['status' => ParticipantStatus::Expired->value, 'email_confirmed_at' => null]);
        $active = $this->userRow($tournament, $user, ParticipantStatus::Confirmed);

        $this->actingAs($user)
            ->post($this->checkInUrl($tournament))
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasNoErrors();

        $this->assertSame(ParticipantStatus::CheckedIn, $active->fresh()?->status);
    }

    public function test_a_withdrawn_guest_row_left_by_a_takeover_does_not_block_the_account(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create(['email' => 'ana@example.com']);
        $handedOver = $this->guest($tournament, ['status' => ParticipantStatus::Withdrawn->value]);
        $active = $this->userRow($tournament, $user, ParticipantStatus::Confirmed);

        $this->actingAs($user)
            ->post($this->checkInUrl($tournament))
            ->assertSessionHasNoErrors();

        $this->assertSame(ParticipantStatus::CheckedIn, $active->fresh()?->status);
        $this->assertSame(ParticipantStatus::Withdrawn, $handedOver->fresh()?->status);
    }

    public function test_a_withdrawn_guest_row_alone_is_not_found(): void
    {
        $tournament = $this->tournament();
        $withdrawn = $this->guest($tournament, ['status' => ParticipantStatus::Withdrawn->value]);

        $this->from(route('tournaments.check-in.show', $tournament->slug))
            ->checkInByEmail($tournament, 'ana@example.com')
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasErrors(['email' => 'No encontramos inscripción con ese email']);

        $this->assertSame(ParticipantStatus::Withdrawn, $withdrawn->fresh()?->status);
    }

    public function test_a_signed_in_user_without_a_registration_is_told_so(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create(['email' => 'ana@example.com']);
        // A guest row with the account email is not the account's registration
        $this->guest($tournament, ['status' => ParticipantStatus::Withdrawn->value]);

        $this->actingAs($user)
            ->from(route('tournaments.check-in.show', $tournament->slug))
            ->post($this->checkInUrl($tournament))
            ->assertSessionHasErrors(['check_in' => 'No estás inscrito en este torneo']);
    }

    public function test_a_registered_participant_checks_in_when_the_tournament_needs_no_manual_confirmation(): void
    {
        $tournament = $this->tournament();
        $participant = $this->guest($tournament, ['status' => ParticipantStatus::Registered->value]);

        $this->checkInByEmail($tournament, 'ana@example.com')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
        $this->assertNotNull($participant->fresh()?->checked_in_at);
    }

    public function test_a_registered_user_checks_in_when_the_tournament_needs_no_manual_confirmation(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $participant = $this->userRow($tournament, $user, ParticipantStatus::Registered);

        $this->actingAs($user)
            ->post($this->checkInUrl($tournament))
            ->assertSessionHasNoErrors();

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    public function test_with_manual_confirmation_a_registered_participant_cannot_check_in(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $participant = $this->guest($tournament, ['status' => ParticipantStatus::Registered->value]);

        $this->from(route('tournaments.check-in.show', $tournament->slug))
            ->checkInByEmail($tournament, 'ana@example.com')
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasErrors([
                'check_in' => 'La organización aún no ha confirmado tu inscripción: no puedes hacer el check-in todavía.',
            ]);

        $this->assertSame(ParticipantStatus::Registered, $participant->fresh()?->status);
    }

    public function test_with_manual_confirmation_a_confirmed_participant_checks_in(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $participant = $this->guest($tournament, ['status' => ParticipantStatus::Confirmed->value]);

        $this->checkInByEmail($tournament, 'ana@example.com')->assertSessionHasNoErrors();

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    public function test_a_second_submit_is_told_the_check_in_is_already_done(): void
    {
        $tournament = $this->tournament();
        $participant = $this->guest($tournament, ['status' => ParticipantStatus::Confirmed->value]);

        $this->checkInByEmail($tournament, 'ana@example.com')->assertSessionHasNoErrors();
        $checkedInAt = $participant->fresh()?->checked_in_at;

        $this->from(route('tournaments.check-in.show', $tournament->slug))
            ->checkInByEmail($tournament, 'ana@example.com')
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasErrors(['check_in' => 'Ya has hecho check-in']);

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
        $this->assertEquals($checkedInAt, $participant->fresh()?->checked_in_at);
    }

    public function test_a_signed_in_user_submitting_twice_is_told_the_check_in_is_already_done(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $this->userRow($tournament, $user, ParticipantStatus::CheckedIn);

        $this->actingAs($user)
            ->from(route('tournaments.check-in.show', $tournament->slug))
            ->post($this->checkInUrl($tournament))
            ->assertSessionHasErrors(['check_in' => 'Ya has hecho check-in']);
    }

    /**
     * @return TestResponse<Response>
     */
    private function checkInByEmail(TournamentModel $tournament, string $email): TestResponse
    {
        return $this->post($this->checkInUrl($tournament), [
            'email' => $email,
            'gdpr_consent' => true,
        ]);
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
    private function tournament(array $attributes = []): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addMinutes(10)]);

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
