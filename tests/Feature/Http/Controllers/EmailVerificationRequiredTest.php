<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Application\Services\SettingsServiceInterface;
use App\Http\Middleware\HandleInertiaRequests;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Mail;
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
 * Signing up and checking in work for guests, so their routes carry no `auth`; when there is a
 * signed-in user they act as that user, so the core's email verification rule applies to them.
 */
final class EmailVerificationRequiredTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string CUTOFF = '2026-01-01T00:00:00+00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        app(SettingsServiceInterface::class)->set('email_verification_enforced_since', self::CUTOFF);
    }

    // Registration

    public function test_a_new_unverified_account_cannot_register_when_verification_is_required(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();
        $user = $this->newUnverifiedUser();

        $this->actingAs($user)
            ->post($this->registrationUrl($tournament))
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseMissing('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    public function test_an_inertia_registration_by_a_new_unverified_account_is_sent_to_the_notice(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->actingAs($this->newUnverifiedUser())
            ->withHeaders($this->inertiaHeaders())
            ->post($this->registrationUrl($tournament))
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseMissing('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    public function test_a_json_registration_by_a_new_unverified_account_gets_a_403(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->actingAs($this->newUnverifiedUser())
            ->postJson($this->registrationUrl($tournament))
            ->assertForbidden()
            ->assertJson(['message' => __('auth.email_verification_required')]);

        $this->assertDatabaseMissing('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    public function test_an_unverified_account_created_before_the_cutoff_can_register(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();
        $user = UserModel::factory()->unverified()->create(['created_at' => '2025-06-01 12:00:00']);

        $this->assertRegistered($this->actingAs($user)->post($this->registrationUrl($tournament)), $tournament, $user);
    }

    public function test_a_verified_account_can_register_when_verification_is_required(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();
        $user = UserModel::factory()->create();

        $this->assertRegistered($this->actingAs($user)->post($this->registrationUrl($tournament)), $tournament, $user);
    }

    public function test_a_guest_can_register_when_verification_is_required(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->post($this->registrationUrl($tournament), [
            'guest_name' => 'Invitado',
            'guest_email' => 'guest@example.com',
            'gdpr_consent' => true,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('tournaments_participants', [
            'tournament_id' => $tournament->id,
            'guest_email' => 'guest@example.com',
        ]);
    }

    public function test_a_new_unverified_account_can_register_when_verification_is_not_required(): void
    {
        $tournament = $this->createOpenTournament();
        $user = $this->newUnverifiedUser();

        $this->assertRegistered($this->actingAs($user)->post($this->registrationUrl($tournament)), $tournament, $user);
    }

    // Check-in

    public function test_a_new_unverified_account_cannot_check_in_when_verification_is_required(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $user = $this->newUnverifiedUser();
        $participant = $this->confirmUser($tournament, $user);

        $this->actingAs($user)
            ->post($this->checkInUrl($tournament))
            ->assertRedirect(route('verification.notice'));

        $this->assertNotCheckedIn($participant);
    }

    public function test_a_json_check_in_by_a_new_unverified_account_gets_a_403(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $user = $this->newUnverifiedUser();
        $participant = $this->confirmUser($tournament, $user);

        $this->actingAs($user)
            ->postJson($this->checkInUrl($tournament))
            ->assertForbidden()
            ->assertJson(['message' => __('auth.email_verification_required')]);

        $this->assertNotCheckedIn($participant);
    }

    public function test_an_unverified_account_created_before_the_cutoff_can_check_in(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $user = UserModel::factory()->unverified()->create(['created_at' => '2025-06-01 12:00:00']);
        $participant = $this->confirmUser($tournament, $user);

        $this->assertCheckedIn($this->actingAs($user)->post($this->checkInUrl($tournament)), $tournament, $participant);
    }

    public function test_a_verified_account_can_check_in_when_verification_is_required(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $user = UserModel::factory()->create();
        $participant = $this->confirmUser($tournament, $user);

        $this->assertCheckedIn($this->actingAs($user)->post($this->checkInUrl($tournament)), $tournament, $participant);
    }

    public function test_a_guest_can_check_in_when_verification_is_required(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $participant = ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'guest_name' => 'Invitado',
            'guest_email' => 'guest@example.com',
            'email_confirmed_at' => now()->subDay(),
            'status' => ParticipantStatus::Confirmed,
            'registered_at' => now()->subDay(),
        ]);

        $response = $this->post($this->checkInUrl($tournament), [
            'email' => 'guest@example.com',
            'gdpr_consent' => true,
        ]);

        $this->assertCheckedIn($response, $tournament, $participant);
    }

    public function test_a_new_unverified_account_can_check_in_when_verification_is_not_required(): void
    {
        $tournament = $this->createTournamentInCheckInWindow();
        $user = $this->newUnverifiedUser();
        $participant = $this->confirmUser($tournament, $user);

        $this->assertCheckedIn($this->actingAs($user)->post($this->checkInUrl($tournament)), $tournament, $participant);
    }

    private function requireVerification(): void
    {
        app(SettingsServiceInterface::class)->set('auth_email_verification_required', '1');
    }

    private function newUnverifiedUser(): UserModel
    {
        return UserModel::factory()->unverified()->create();
    }

    /**
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    private function registrationUrl(TournamentModel $tournament): string
    {
        return route('tournaments.registration.store', ['tournamentId' => $tournament->id]);
    }

    private function checkInUrl(TournamentModel $tournament): string
    {
        return route('tournaments.check-in.store', ['slug' => $tournament->slug]);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertRegistered(TestResponse $response, TournamentModel $tournament, UserModel $user): void
    {
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('tournaments_participants', [
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertCheckedIn(TestResponse $response, TournamentModel $tournament, ParticipantModel $participant): void
    {
        $response->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHas('success');

        $this->assertSame(ParticipantStatus::CheckedIn, $participant->fresh()?->status);
    }

    private function assertNotCheckedIn(ParticipantModel $participant): void
    {
        $fresh = $participant->fresh();

        $this->assertSame(ParticipantStatus::Confirmed, $fresh?->status);
        $this->assertNull($fresh->checked_in_at);
    }

    private function createOpenTournament(): TournamentModel
    {
        $event = EventModel::factory()->create(['start_date' => now()->addWeek()]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo ' . Str::random(8),
            'slug' => 'torneo-' . Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'registration_opens_at' => now()->subWeek(),
        ]);
    }

    private function createTournamentInCheckInWindow(): TournamentModel
    {
        $event = EventModel::factory()->create(['start_date' => now()->addMinutes(10)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo ' . Str::random(8),
            'slug' => 'torneo-' . Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationClosed,
            'allow_guests' => true,
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'registration_opens_at' => now()->subWeek(),
        ]);
    }

    private function confirmUser(TournamentModel $tournament, UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Confirmed,
            'registered_at' => now()->subDay(),
        ]);
    }
}
