<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

final class GuestCancellationControllerTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string TOKEN = 'guest-cancellation-token';

    protected function setUp(): void
    {
        parent::setUp();
        // The module's routes are registered after boot; index their names
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function guestRegistration(
        string $tournamentStatus = 'registration_open',
        ParticipantStatus $participantStatus = ParticipantStatus::Registered,
    ): TournamentModel {
        $event = EventModel::factory()->published()->create([
            // app.timezone is Europe/Madrid, so this is a venue local time
            'start_date' => '2036-03-14 10:00:00',
            'end_date' => '2036-03-14 20:00:00',
        ]);

        $tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de Warhammer',
            'slug' => 'torneo-warhammer',
            'status' => $tournamentStatus,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'allow_guests' => true,
        ]);

        ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'cancellation_token' => self::TOKEN,
            'status' => $participantStatus,
            'registered_at' => now(),
        ]);

        return $tournament;
    }

    public function test_guest_sees_the_cancellation_page_with_the_event_date(): void
    {
        $this->guestRegistration();

        $this->get('/torneos/cancelar/'.self::TOKEN)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.name', 'Torneo de Warhammer')
                ->where('tournament.eventDate', '2036-03-14T10:00:00+01:00')
                ->where('participant.guest_name', 'Ana')
                ->where('canCancel', true));
    }

    public function test_withdrawn_guest_cannot_cancel_again(): void
    {
        $this->guestRegistration(participantStatus: ParticipantStatus::Withdrawn);

        $this->get('/torneos/cancelar/'.self::TOKEN)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCancel', false));
    }

    public function test_guest_cannot_cancel_once_the_tournament_has_started(): void
    {
        $this->guestRegistration(tournamentStatus: 'in_progress');

        $this->get('/torneos/cancelar/'.self::TOKEN)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canCancel', false));
    }

    public function test_cancelling_a_started_tournament_redirects_with_an_error(): void
    {
        $this->guestRegistration(tournamentStatus: 'in_progress');

        $this->delete('/torneos/cancelar/'.self::TOKEN)
            ->assertRedirect(route('tournaments.index'))
            ->assertSessionHas('error', __('tournaments::messages.errors.cannot_withdraw'));
    }

    public function test_guest_cancels_an_open_registration(): void
    {
        $this->guestRegistration();

        $this->delete('/torneos/cancelar/'.self::TOKEN)
            ->assertRedirect(route('tournaments.index'))
            ->assertSessionHas('success');

        $this->assertSame(
            ParticipantStatus::Withdrawn,
            ParticipantModel::query()->where('cancellation_token', self::TOKEN)->firstOrFail()->status,
        );
    }
}
