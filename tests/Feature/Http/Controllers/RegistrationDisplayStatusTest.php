<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The status badge follows what the entity says about registration (dates and event start),
 * while the raw status stays untouched.
 */
final class RegistrationDisplayStatusTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_event_page_loads_its_tournament_once(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00');
        $event = EventModel::query()->findOrFail($tournament->event_id);

        DB::enableQueryLog();
        $this->actingAs(UserModel::factory()->create())->get(route('events.show', $event->slug))->assertOk();
        $lookups = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['query'], 'tournaments_tournaments') && str_contains($query['query'], '"event_id" = ?'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $lookups);
    }

    public function test_the_page_says_registration_is_coming_soon_before_the_opening_date(): void
    {
        $opensAt = now()->addDays(3)->startOfMinute();
        $tournament = $this->tournament('2036-03-14 10:00:00', ['registration_opens_at' => $opensAt]);

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.status', 'registration_open')
                ->where('tournament.display_status', 'registration_upcoming')
                ->where('tournament.status_label', 'Inscripción próximamente')
                ->where('tournament.registration_opens_at', app(TimezoneServiceInterface::class)->iso($opensAt)));
    }

    public function test_the_page_says_registration_is_closed_after_the_closing_date(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00', ['registration_closes_at' => now()->subMinute()]);

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.status', 'registration_open')
                ->where('tournament.display_status', 'registration_closed')
                ->where('tournament.status_label', 'Inscripción cerrada'));
    }

    public function test_the_page_says_registration_is_closed_once_the_event_has_started(): void
    {
        $tournament = $this->tournament(now()->subMinute());

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.status', 'registration_open')
                ->where('tournament.display_status', 'registration_closed')
                ->where('tournament.status_label', 'Inscripción cerrada'));
    }

    public function test_the_page_says_registration_is_open_when_it_is(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00', [
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
        ]);

        $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.display_status', 'registration_open')
                ->where('tournament.status_label', 'Inscripción abierta'));
    }

    public function test_the_event_page_section_gets_the_same_label(): void
    {
        $tournament = $this->tournament('2036-03-14 10:00:00', ['registration_opens_at' => now()->addDay()]);
        $event = EventModel::query()->findOrFail($tournament->event_id);

        $this->get(route('events.show', $event->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.status_label', 'Inscripción próximamente')
                ->where('tournament.display_status', 'registration_upcoming'));
    }

    public function test_the_listing_gets_the_same_label_and_keeps_the_raw_status(): void
    {
        $this->tournament('2036-03-14 10:00:00', ['registration_opens_at' => now()->addDay()]);

        $this->get('/torneos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournaments.data.0.status', 'registration_open')
                ->where('tournaments.data.0.displayStatus', 'registration_upcoming')
                ->where('tournaments.data.0.statusLabel', 'Inscripción próximamente'));
    }

    public function test_the_listing_says_closed_once_the_event_has_started(): void
    {
        $this->tournament(now()->subMinute());

        $this->get('/torneos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournaments.data.0.status', 'registration_open')
                ->where('tournaments.data.0.statusLabel', 'Inscripción cerrada'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(DateTimeInterface|string $eventStartsAt, array $attributes = []): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => is_string($eventStartsAt) ? CarbonImmutable::parse($eventStartsAt, 'Europe/Madrid') : $eventStartsAt]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo '.Str::random(8),
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            ...$attributes,
        ]);
    }
}
