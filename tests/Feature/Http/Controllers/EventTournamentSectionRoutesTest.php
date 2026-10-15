<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Application\Services\SettingsServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The tournament section on the event page (components/EventTournamentSection.vue) registers
 * and withdraws through the same routes as the tournament page. There are no Vue component
 * tests in this module, so this checks that the requests the component builds reach those
 * routes and that the routes accept them. `json()` sends what the component's fetch calls send:
 * a JSON body with `Accept` and `Content-Type` set to `application/json`.
 */
final class EventTournamentSectionRoutesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string COMPONENT = __DIR__ . '/../../../../resources/js/components/EventTournamentSection.vue';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_the_component_does_not_call_api_prefixed_routes(): void
    {
        $this->assertStringNotContainsString('/api/', $this->componentSource());
    }

    public function test_every_request_of_the_component_reaches_a_registration_route(): void
    {
        $source = $this->componentSource();

        if (preg_match('/const registrationUrl = \(\w+: string\): string => `([^`]+)`;/', $source, $urlMatch) !== 1) {
            $this->fail('The component builds its URLs with registrationUrl()');
        }
        $url = preg_replace('/\$\{\w+\}/', (string) Str::uuid(), $urlMatch[1]);
        $this->assertIsString($url);

        preg_match_all('/fetch\(registrationUrl\([^)]*\),\s*\{\s*method:\s*\'(\w+)\'/', $source, $methodMatches);
        $methods = $methodMatches[1];
        sort($methods);
        $this->assertSame(['DELETE', 'POST', 'POST'], $methods, 'Register (user and guest) and withdraw');

        $expectedNames = [
            'POST' => 'tournaments.registration.store',
            'DELETE' => 'tournaments.registration.destroy',
        ];

        foreach (array_unique($methods) as $method) {
            $route = Route::getRoutes()->match(Request::create($url, $method));

            $this->assertSame($expectedNames[$method], $route->getName(), "{$method} {$url}");
        }
    }

    public function test_the_old_api_prefixed_url_does_not_exist(): void
    {
        $tournament = $this->createOpenTournament();

        $this->actingAs(UserModel::factory()->create())
            ->json('POST', "/api/torneos/{$tournament->id}/inscripcion", [])
            ->assertNotFound();
    }

    public function test_a_signed_in_user_registers_with_the_component_request(): void
    {
        $tournament = $this->createOpenTournament();
        $user = UserModel::factory()->create();

        $this->actingAs($user)
            ->json('POST', $this->registrationUrl($tournament), [])
            ->assertCreated();

        $this->assertDatabaseHas('tournaments_participants', [
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_a_guest_registers_with_the_component_request(): void
    {
        $tournament = $this->createOpenTournament();

        $this->json('POST', $this->registrationUrl($tournament), [
            'guest_name' => 'Invitado',
            'guest_email' => 'guest@example.com',
            'gdpr_consent' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('tournaments_participants', [
            'tournament_id' => $tournament->id,
            'guest_email' => 'guest@example.com',
        ]);
    }

    public function test_guest_validation_errors_come_back_as_a_422_with_the_field_errors(): void
    {
        $tournament = $this->createOpenTournament();

        $this->json('POST', $this->registrationUrl($tournament), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guest_name', 'guest_email', 'gdpr_consent']);
    }

    public function test_a_signed_in_user_withdraws_with_the_component_request(): void
    {
        $tournament = $this->createOpenTournament();
        $user = UserModel::factory()->create();
        $participant = ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Registered,
            'registered_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->json('DELETE', $this->registrationUrl($tournament), [])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $this->assertSame(ParticipantStatus::Withdrawn, $participant->fresh()?->status);
    }

    public function test_a_new_unverified_account_gets_the_403_the_component_sends_to_the_notice(): void
    {
        $settings = app(SettingsServiceInterface::class);
        $settings->set('email_verification_enforced_since', '2026-01-01T00:00:00+00:00');
        $settings->set('auth_email_verification_required', '1');
        $tournament = $this->createOpenTournament();

        $this->actingAs(UserModel::factory()->unverified()->create())
            ->json('POST', $this->registrationUrl($tournament), [])
            ->assertForbidden()
            ->assertHeader('X-Email-Verification-Required', '1')
            ->assertJson([
                'error' => 'email_verification_required',
                'message' => __('auth.email_verification_required'),
            ]);

        $this->assertDatabaseMissing('tournaments_participants', ['tournament_id' => $tournament->id]);
    }

    private function componentSource(): string
    {
        $source = file_get_contents(self::COMPONENT);
        $this->assertIsString($source);

        return $source;
    }

    private function registrationUrl(TournamentModel $tournament): string
    {
        return "/torneos/{$tournament->id}/inscripcion";
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
}
