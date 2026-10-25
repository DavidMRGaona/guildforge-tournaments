<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The confirmation link opens a page with a button: only the button (POST) confirms, so the
 * link scanners of mail providers never confirm a registration on their own.
 */
final class GuestConfirmationControllerTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string TOKEN = 'guest-confirmation-token';

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Madrid');
        $this->travelTo($this->now);
        Event::fake([ParticipantRegistered::class]);
    }

    /**
     * @param  array<string, mixed>  $tournamentAttributes
     * @param  array<string, mixed>  $participantAttributes
     */
    private function pendingGuest(array $tournamentAttributes = [], array $participantAttributes = []): TournamentModel
    {
        $tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->published()->create(['start_date' => $this->now->addDays(3)])->id,
            'name' => 'Torneo de Warhammer',
            'slug' => 'torneo-warhammer',
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
            'allow_guests' => true,
            ...$tournamentAttributes,
        ]);

        ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'cancellation_token' => 'guest-cancellation-token',
            'confirmation_token' => self::TOKEN,
            'confirmation_mode' => GuestConfirmationMode::Hold->value,
            'confirmation_expires_at' => $this->now->addHours(2),
            'confirmation_sent_at' => $this->now,
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => $this->now,
            ...$participantAttributes,
        ]);

        return $tournament;
    }

    private function row(): ParticipantModel
    {
        return ParticipantModel::query()->where('confirmation_token', self::TOKEN)->firstOrFail();
    }

    private function assertResultPage(string $url, string $outcome, string $message): void
    {
        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/ConfirmRegistration', false)
                ->where('result.outcome', $outcome)
                ->where('result.message', $message));
    }

    public function test_the_link_shows_the_page_without_confirming(): void
    {
        $this->pendingGuest();

        $this->get('/torneos/confirmar/'.self::TOKEN)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/ConfirmRegistration', false)
                ->where('token', self::TOKEN)
                ->where('confirmation.state', 'pending')
                ->where('confirmation.tournament_name', 'Torneo de Warhammer')
                ->where('confirmation.tournament_slug', 'torneo-warhammer')
                ->where('confirmation.guest_name', 'Ana')
                ->where('result', null)
                ->where('confirmation.expires_at', $this->now->addHours(2)->format('c'))
                ->where('confirmation.seat_held', true))
            ->assertSee('<meta name="robots" content="noindex, nofollow" data-seo-server>', false);

        $row = $this->row();
        $this->assertNull($row->email_confirmed_at);
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_an_unknown_link_shows_the_invalid_page(): void
    {
        $this->get('/torneos/confirmar/no-such-token')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tournaments/ConfirmRegistration', false)
                ->where('confirmation.state', 'invalid')
                ->where('result', null));
    }

    public function test_the_button_confirms_and_returns_to_the_confirmation_page(): void
    {
        $this->pendingGuest();

        $this->post('/torneos/confirmar/'.self::TOKEN)
            ->assertRedirect('/torneos/confirmar/'.self::TOKEN);

        $this->assertResultPage('/torneos/confirmar/'.self::TOKEN, 'confirmed', 'Inscripción confirmada. Te hemos enviado un email con los detalles.');

        $this->assertEquals($this->now, $this->row()->email_confirmed_at);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
    }

    public function test_a_second_press_says_it_was_already_confirmed(): void
    {
        $this->pendingGuest(participantAttributes: ['email_confirmed_at' => $this->now, 'confirmation_expires_at' => null]);

        $this->post('/torneos/confirmar/'.self::TOKEN)
            ->assertRedirect('/torneos/confirmar/'.self::TOKEN);

        $this->assertResultPage('/torneos/confirmar/'.self::TOKEN, 'already_confirmed', 'Tu inscripción ya estaba confirmada.');

        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_an_unknown_link_returns_to_the_confirmation_page(): void
    {
        $this->post('/torneos/confirmar/no-such-token')
            ->assertRedirect('/torneos/confirmar/'.'no-such-token');

        $this->assertResultPage('/torneos/confirmar/'.'no-such-token', 'invalid', 'Este enlace ya no es válido. Si las inscripciones siguen abiertas, puedes volver a apuntarte.');
    }

    public function test_a_cancelled_registration_link_is_no_longer_valid(): void
    {
        $this->pendingGuest(participantAttributes: ['status' => ParticipantStatus::Withdrawn->value]);

        $this->post('/torneos/confirmar/'.self::TOKEN)
            ->assertRedirect('/torneos/confirmar/'.self::TOKEN);

        $this->assertResultPage('/torneos/confirmar/'.self::TOKEN, 'invalid', 'Este enlace ya no es válido. Si las inscripciones siguen abiertas, puedes volver a apuntarte.');
    }

    public function test_an_overdue_link_is_no_longer_valid(): void
    {
        $this->pendingGuest();
        $this->travelTo($this->now->addHours(2)->addMinute());

        $this->post('/torneos/confirmar/'.self::TOKEN)
            ->assertRedirect('/torneos/confirmar/'.self::TOKEN);

        $this->assertResultPage('/torneos/confirmar/'.self::TOKEN, 'invalid', 'Este enlace ya no es válido. Si las inscripciones siguen abiertas, puedes volver a apuntarte.');

        $this->assertSame(ParticipantStatus::Expired, $this->row()->status);
    }

    public function test_after_the_close_the_button_says_registration_has_closed(): void
    {
        $this->pendingGuest(['status' => TournamentStatus::RegistrationClosed->value]);

        $this->post('/torneos/confirmar/'.self::TOKEN)
            ->assertRedirect('/torneos/confirmar/'.self::TOKEN);

        $this->assertResultPage('/torneos/confirmar/'.self::TOKEN, 'registration_closed', 'Las inscripciones ya se han cerrado.');

        $this->assertSame(ParticipantStatus::Expired, $this->row()->status);
    }

    public function test_a_wait_registration_in_a_full_tournament_says_it_filled_up(): void
    {
        $tournament = $this->pendingGuest(['max_participants' => 1], [
            'confirmation_mode' => GuestConfirmationMode::Wait->value,
            'confirmation_expires_at' => null,
        ]);
        ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Berta',
            'guest_email' => 'berta@example.com',
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => $this->now,
            'email_confirmed_at' => $this->now,
        ]);

        $this->post('/torneos/confirmar/'.self::TOKEN)
            ->assertRedirect('/torneos/confirmar/'.self::TOKEN);

        $this->assertResultPage('/torneos/confirmar/'.self::TOKEN, 'full', 'Se ha llenado mientras confirmabas.');

        $this->assertSame(ParticipantStatus::Expired, $this->row()->status);
    }

    public function test_the_link_is_limited_per_ip(): void
    {
        $this->pendingGuest();

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->get('/torneos/confirmar/'.self::TOKEN)->assertOk();
        }

        $this->get('/torneos/confirmar/'.self::TOKEN)
            ->assertStatus(429)
            ->assertSee('Demasiados intentos con este enlace. Espera un momento y vuelve a intentarlo.');
    }
}
