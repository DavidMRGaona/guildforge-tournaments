<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Application\Services\SettingsServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What the tournament page, the check-in page and the event page give the frontend so it can
 * disable signing up, withdrawing and checking in while the account must verify its email
 * (`auth.user.mustVerifyEmail`), and the 403 those routes answer a fetch() with when it is sent
 * anyway. Accounts that need no verification and guests get the same pages and buttons as
 * before: the module never hides a button for this, it only disables it on the client.
 */
final class VerificationGatePagesTest extends ModuleTestCase
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

    // Tournament page (Show.vue)

    public function test_the_tournament_page_tells_a_must_verify_account_and_keeps_its_register_button(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->actingAs($this->newUnverifiedUser())
            ->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/Show', false)
                ->where('auth.user.mustVerifyEmail', true)
                ->where('canRegister', true)
                ->where('userRegistration', null));
    }

    public function test_the_tournament_page_tells_a_registered_must_verify_account(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();
        $user = $this->newUnverifiedUser();
        $this->registerUser($tournament, $user);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/Show', false)
                ->where('auth.user.mustVerifyEmail', true)
                ->where('userRegistration.status', ParticipantStatus::Registered->value)
                ->where('tournament.is_registration_open', true));
    }

    public function test_the_tournament_page_does_not_block_a_verified_account(): void
    {
        $this->requireVerification();

        $this->assertShowNotBlocked(UserModel::factory()->create());
    }

    public function test_the_tournament_page_does_not_block_an_account_created_before_the_cutoff(): void
    {
        $this->requireVerification();

        $this->assertShowNotBlocked($this->oldUnverifiedUser());
    }

    public function test_the_tournament_page_does_not_block_anyone_while_verification_is_not_required(): void
    {
        $this->assertShowNotBlocked($this->newUnverifiedUser());
    }

    public function test_the_tournament_page_gives_a_guest_no_user_and_the_same_register_button(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/Show', false)
                ->where('auth.user', null)
                ->where('canRegister', true));
    }

    // Event page (EventTournamentSection.vue)

    public function test_the_event_page_tells_a_must_verify_account_and_keeps_the_section_buttons(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->actingAs($this->newUnverifiedUser())
            ->get(route('events.show', $this->eventSlug($tournament)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.mustVerifyEmail', true)
                ->where('tournament.id', $tournament->id)
                ->where('canRegister', true));
    }

    public function test_the_event_page_does_not_block_a_verified_account(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->actingAs(UserModel::factory()->create())
            ->get(route('events.show', $this->eventSlug($tournament)))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.mustVerifyEmail', false)
                ->where('canRegister', true));
    }

    // Check-in page (CheckIn.vue)

    public function test_the_check_in_page_tells_a_registered_must_verify_account(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $user = $this->newUnverifiedUser();
        $this->confirmUser($tournament, $user);

        $this->actingAs($user)
            ->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/CheckIn', false)
                ->where('auth.user.mustVerifyEmail', true)
                ->where('checkInWindow.status', 'open')
                ->where('userRegistration.status', ParticipantStatus::Confirmed->value));
    }

    public function test_the_check_in_page_does_not_block_an_account_created_before_the_cutoff(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $user = $this->oldUnverifiedUser();
        $this->confirmUser($tournament, $user);

        $this->actingAs($user)
            ->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.mustVerifyEmail', false)
                ->where('checkInWindow.status', 'open'));
    }

    public function test_the_check_in_page_gives_a_guest_no_user(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();

        $this->get(route('tournaments.check-in.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', null)
                ->where('checkInWindow.status', 'open'));
    }

    // The 403 a fetch() gets when it is sent anyway

    public function test_registering_answers_a_must_verify_account_with_the_verification_403(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();

        $this->assertVerificationForbidden(
            $this->actingAs($this->newUnverifiedUser())->postJson($this->registrationUrl($tournament)),
        );

        $this->assertDatabaseMissing('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    public function test_withdrawing_answers_a_must_verify_account_with_the_verification_403(): void
    {
        $this->requireVerification();
        $tournament = $this->createOpenTournament();
        $user = $this->newUnverifiedUser();
        $participant = $this->registerUser($tournament, $user);

        $this->assertVerificationForbidden(
            $this->actingAs($user)->deleteJson($this->registrationUrl($tournament)),
        );

        $this->assertSame(ParticipantStatus::Registered, $participant->fresh()?->status);
    }

    public function test_checking_in_answers_a_must_verify_account_with_the_verification_403(): void
    {
        $this->requireVerification();
        $tournament = $this->createTournamentInCheckInWindow();
        $user = $this->newUnverifiedUser();
        $participant = $this->confirmUser($tournament, $user);

        $this->assertVerificationForbidden(
            $this->actingAs($user)->postJson(route('tournaments.check-in.store', $tournament->slug)),
        );

        $this->assertSame(ParticipantStatus::Confirmed, $participant->fresh()?->status);
    }

    // Withdrawing for accounts that need no verification: unchanged

    public function test_a_verified_account_can_withdraw_when_verification_is_required(): void
    {
        $this->requireVerification();

        $this->assertWithdraws(UserModel::factory()->create());
    }

    public function test_an_account_created_before_the_cutoff_can_withdraw(): void
    {
        $this->requireVerification();

        $this->assertWithdraws($this->oldUnverifiedUser());
    }

    public function test_a_new_unverified_account_can_withdraw_when_verification_is_not_required(): void
    {
        $this->assertWithdraws($this->newUnverifiedUser());
    }

    private function assertShowNotBlocked(UserModel $user): void
    {
        $tournament = $this->createOpenTournament();

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/Show', false)
                ->where('auth.user.mustVerifyEmail', false)
                ->where('canRegister', true));
    }

    private function assertWithdraws(UserModel $user): void
    {
        $tournament = $this->createOpenTournament();
        $participant = $this->registerUser($tournament, $user);

        $this->actingAs($user)
            ->deleteJson($this->registrationUrl($tournament))
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertSame(ParticipantStatus::Withdrawn, $participant->fresh()?->status);
    }

    /**
     * The shape the core's useVerificationGate().isVerificationRequired() recognises.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertVerificationForbidden(TestResponse $response): void
    {
        $response->assertForbidden()
            ->assertHeader('X-Email-Verification-Required', '1')
            ->assertJson([
                'error' => 'email_verification_required',
                'message' => __('auth.email_verification_required'),
            ]);
    }

    private function requireVerification(): void
    {
        app(SettingsServiceInterface::class)->set('auth_email_verification_required', '1');
    }

    private function newUnverifiedUser(): UserModel
    {
        return UserModel::factory()->unverified()->create();
    }

    private function oldUnverifiedUser(): UserModel
    {
        return UserModel::factory()->unverified()->create(['created_at' => '2025-06-01 12:00:00']);
    }

    private function registrationUrl(TournamentModel $tournament): string
    {
        return route('tournaments.registration.store', ['tournamentId' => $tournament->id]);
    }

    private function eventSlug(TournamentModel $tournament): string
    {
        $event = EventModel::query()->findOrFail($tournament->event_id);

        return (string) $event->slug;
    }

    private function createOpenTournament(): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addWeek()]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo '.Str::random(8),
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'registration_opens_at' => now()->subWeek(),
        ]);
    }

    private function createTournamentInCheckInWindow(): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addMinutes(10)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo '.Str::random(8),
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
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

    private function registerUser(TournamentModel $tournament, UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now()->subDay(),
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
