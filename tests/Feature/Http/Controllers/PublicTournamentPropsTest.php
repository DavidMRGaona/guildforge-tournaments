<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The organisation's notification email is for the panel and the emails it receives:
 * the public pages never send it to the browser.
 */
final class PublicTournamentPropsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const ORGANISER_EMAIL = 'avisos-organizacion@example.com';

    /**
     * @return array<string, array{string}>
     */
    public static function tournamentPages(): array
    {
        return [
            'tournament page' => ['tournaments.show'],
            'standings page' => ['tournaments.standings'],
            'rounds page' => ['tournaments.rounds'],
            'check-in page' => ['tournaments.check-in.show'],
        ];
    }

    #[DataProvider('tournamentPages')]
    public function test_a_tournament_page_does_not_carry_the_notification_email(string $routeName): void
    {
        $tournament = $this->tournament();

        $this->get(route($routeName, $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.id', $tournament->id)
                ->missing('tournament.notification_email'))
            ->assertDontSee(self::ORGANISER_EMAIL);
    }

    public function test_the_event_page_does_not_carry_the_notification_email(): void
    {
        $tournament = $this->tournament();
        $event = EventModel::query()->findOrFail($tournament->event_id);

        $this->get(route('events.show', $event->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tournament.id', $tournament->id)
                ->missing('tournament.notification_email'))
            ->assertDontSee(self::ORGANISER_EMAIL);
    }

    public function test_the_registration_status_json_does_not_carry_the_notification_email(): void
    {
        $tournament = $this->tournament();

        $this->getJson(route('tournaments.registration.show', $tournament->id))
            ->assertOk()
            ->assertDontSee(self::ORGANISER_EMAIL);
    }

    private function tournament(): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addMinutes(10)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo público',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => self::ORGANISER_EMAIL,
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
        ]);
    }
}
