<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The public registration endpoint: each answer of a guest submission carries its own message,
 * guests are limited per IP and the form gets the confirmation terms before submitting.
 */
final class GuestRegistrationEndpointTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string USER_AGENT = 'Mozilla/5.0 (Test)';

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00');
        $this->travelTo($this->now);
        config()->set('modules.settings.tournaments', []);
        Event::fake([GuestConfirmationRequested::class, ParticipantRegistered::class]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->published()->create(['start_date' => $this->now->addDays(3)])->id,
            'name' => 'Torneo de primavera',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ]);
    }

    private function url(TournamentModel $tournament): string
    {
        return "/torneos/{$tournament->id}/inscripcion";
    }

    /**
     * @return array<string, mixed>
     */
    private function guestForm(string $email = 'ana@example.com'): array
    {
        return ['guest_name' => 'Ana', 'guest_email' => $email, 'gdpr_consent' => true];
    }

    public function test_a_guest_submit_answers_with_the_confirmation_notice(): void
    {
        $tournament = $this->tournament();

        $this->withHeader('User-Agent', self::USER_AGENT)
            ->json('POST', $this->url($tournament), $this->guestForm())
            ->assertCreated()
            ->assertJsonPath('message', 'Te hemos enviado un email para confirmar la inscripción. Confírmala antes del 02/11/2026 12:00 o la plaza se liberará.')
            ->assertJsonPath('data.outcome', 'confirmation_sent')
            ->assertJsonPath('data.seat_held', true)
            ->assertJsonPath('data.expires_at', '2026-11-02T12:00:00+01:00');

        $row = ParticipantModel::query()->where('tournament_id', $tournament->id)->firstOrFail();
        $this->assertSame(
            hash_hmac('sha256', '127.0.0.1|'.self::USER_AGENT, (string) config('app.key')),
            $row->fingerprint,
        );
    }

    public function test_a_guest_without_a_held_seat_is_told_it_is_not_guaranteed(): void
    {
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament();

        $this->json('POST', $this->url($tournament), $this->guestForm())
            ->assertCreated()
            ->assertJsonPath('message', 'Te hemos enviado un email para confirmar la inscripción. Tu plaza no está garantizada hasta que la confirmes.')
            ->assertJsonPath('data.seat_held', false);
    }

    public function test_a_pending_resubmit_answers_200(): void
    {
        $tournament = $this->tournament();
        $this->json('POST', $this->url($tournament), $this->guestForm())->assertCreated();

        $this->json('POST', $this->url($tournament), $this->guestForm('ANA@example.com'))
            ->assertOk()
            ->assertJsonPath('message', 'Ya tienes una inscripción pendiente: revisa tu correo.')
            ->assertJsonPath('data.outcome', 'confirmation_pending');

        $this->travelTo($this->now->addMinutes(6));

        $this->json('POST', $this->url($tournament), $this->guestForm())
            ->assertOk()
            ->assertJsonPath('message', 'Ya tienes una inscripción pendiente: revisa tu correo.')
            ->assertJsonPath('data.outcome', 'confirmation_resent');
    }

    public function test_a_browser_submit_gets_the_notice_as_a_flash(): void
    {
        $tournament = $this->tournament();

        $this->from('/torneos/'.$tournament->slug)
            ->post($this->url($tournament), $this->guestForm())
            ->assertRedirect('/torneos/'.$tournament->slug)
            ->assertSessionHas('success', 'Te hemos enviado un email para confirmar la inscripción. Confírmala antes del 02/11/2026 12:00 o la plaza se liberará.');
    }

    public function test_a_confirmed_guest_email_is_refused_as_taken(): void
    {
        $tournament = $this->tournament();
        ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'email_confirmed_at' => $this->now->subDay(),
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => $this->now->subDay(),
        ]);

        $this->json('POST', $this->url($tournament), $this->guestForm())
            ->assertStatus(400)
            ->assertJsonPath('message', 'Ya hay una inscripción con este email.');
    }

    public function test_an_account_email_answers_409_with_the_login_url(): void
    {
        UserModel::factory()->create(['email' => 'ana@example.com']);
        $tournament = $this->tournament();

        $this->json('POST', $this->url($tournament), $this->guestForm())
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'Este email pertenece a una cuenta. Inicia sesión para inscribirte.',
                'login_url' => route('login'),
            ]);

        $this->assertSame(0, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_a_browser_submit_with_an_account_email_goes_back_with_the_login_message(): void
    {
        UserModel::factory()->create(['email' => 'ana@example.com']);
        $tournament = $this->tournament();

        $this->from('/torneos/'.$tournament->slug)
            ->post($this->url($tournament), $this->guestForm())
            ->assertRedirect('/torneos/'.$tournament->slug)
            ->assertSessionHas('error', 'Este email pertenece a una cuenta. Inicia sesión para inscribirte.');
    }

    public function test_a_signed_in_user_takes_over_their_guest_registration(): void
    {
        $user = UserModel::factory()->create(['email' => 'ana@example.com']);
        $tournament = $this->tournament();
        $guestRow = ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'email_confirmed_at' => $this->now->subDay(),
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => $this->now->subDay(),
        ]);

        $this->actingAs($user)
            ->withHeader('User-Agent', self::USER_AGENT)
            ->json('POST', $this->url($tournament), [])
            ->assertCreated()
            ->assertJsonPath('message', 'Ya tenías una inscripción como invitado; ahora está en tu cuenta.')
            ->assertJsonPath('data.claimed_guest_registration', true);

        $row = $guestRow->fresh();
        $this->assertNotNull($row);
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame(
            hash_hmac('sha256', '127.0.0.1|'.self::USER_AGENT, (string) config('app.key')),
            $row->fingerprint,
        );
    }

    public function test_a_signed_in_registration_records_the_fingerprint(): void
    {
        $user = UserModel::factory()->create();
        $tournament = $this->tournament();

        $this->actingAs($user)
            ->withHeader('User-Agent', self::USER_AGENT)
            ->json('POST', $this->url($tournament), [])
            ->assertCreated()
            ->assertJsonPath('message', 'Inscripción realizada correctamente');

        $row = ParticipantModel::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(
            hash_hmac('sha256', '127.0.0.1|'.self::USER_AGENT, (string) config('app.key')),
            $row->fingerprint,
        );
    }

    public function test_an_unverified_user_with_a_guest_registration_of_their_email_is_refused(): void
    {
        $user = UserModel::factory()->unverified()->create(['email' => 'ana@example.com']);
        $tournament = $this->tournament();
        ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'email_confirmed_at' => $this->now->subDay(),
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => $this->now->subDay(),
        ]);

        $this->actingAs($user)
            ->json('POST', $this->url($tournament), [])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Ya hay una inscripción con tu email.');
    }

    public function test_a_registered_user_is_told_they_are_already_registered(): void
    {
        $user = UserModel::factory()->create();
        $tournament = $this->tournament();
        $this->actingAs($user)->json('POST', $this->url($tournament), [])->assertCreated();

        $this->actingAs($user)
            ->json('POST', $this->url($tournament), [])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Ya estás inscrito en este torneo');
    }

    public function test_the_sixth_guest_submit_in_a_minute_is_429_with_the_spanish_message(): void
    {
        $tournament = $this->tournament();

        for ($i = 1; $i <= 5; $i++) {
            $this->json('POST', $this->url($tournament), $this->guestForm("guest{$i}@example.com"))->assertCreated();
        }

        $this->json('POST', $this->url($tournament), $this->guestForm('guest6@example.com'))
            ->assertStatus(429)
            ->assertExactJson(['message' => 'Has hecho demasiados intentos. Espera un momento y vuelve a probar.']);

        $this->assertSame(5, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());

        // The minute limit lifts after a minute
        $this->travelTo($this->now->addMinutes(2));
        $this->json('POST', $this->url($tournament), $this->guestForm('guest6@example.com'))->assertCreated();
    }

    public function test_the_hourly_limit_comes_from_the_settings(): void
    {
        config()->set('modules.settings.tournaments', ['guest_rate_limit' => ['per_minute' => 10, 'per_hour' => 3]]);
        $tournament = $this->tournament();

        for ($i = 1; $i <= 3; $i++) {
            $this->travelTo($this->now->addMinutes($i * 2));
            $this->json('POST', $this->url($tournament), $this->guestForm("guest{$i}@example.com"))->assertCreated();
        }

        $this->travelTo($this->now->addMinutes(10));
        $this->json('POST', $this->url($tournament), $this->guestForm('guest4@example.com'))->assertStatus(429);
    }

    public function test_a_browser_submit_over_the_limit_goes_back_with_the_form_error(): void
    {
        config()->set('modules.settings.tournaments', ['guest_rate_limit' => ['per_minute' => 1]]);
        $tournament = $this->tournament();
        $this->json('POST', $this->url($tournament), $this->guestForm())->assertCreated();

        $this->from('/torneos/'.$tournament->slug)
            ->withHeader('X-Inertia', 'true')
            ->post($this->url($tournament), $this->guestForm('otra@example.com'))
            ->assertRedirect('/torneos/'.$tournament->slug)
            ->assertSessionHasErrors(['registration' => 'Has hecho demasiados intentos. Espera un momento y vuelve a probar.']);
    }

    public function test_a_signed_in_user_is_not_guest_limited(): void
    {
        $user = UserModel::factory()->create();

        for ($i = 1; $i <= 6; $i++) {
            $this->actingAs($user)
                ->json('POST', $this->url($this->tournament()), [])
                ->assertCreated();
        }
    }

    public function test_the_event_page_shares_the_guest_confirmation_terms(): void
    {
        $tournament = $this->tournament(['registration_closes_at' => $this->now->addMinutes(30)]);
        $event = EventModel::query()->findOrFail($tournament->event_id);

        $this->get(route('events.show', $event->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('guestConfirmation.mode', 'hold')
                ->where('guestConfirmation.expires_at', '2026-11-02T10:30:00+01:00'));
    }

    public function test_the_tournament_page_passes_them(): void
    {
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['max_unconfirmed_held' => 0]]);
        $tournament = $this->tournament();

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/Show', false)
                ->where('guestConfirmation.mode', 'wait')
                ->where('guestConfirmation.expires_at', null));
    }

    public function test_the_tournament_page_has_no_terms_without_guests(): void
    {
        $tournament = $this->tournament(['allow_guests' => false]);

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/Show', false)
                ->where('guestConfirmation', null));
    }
}
