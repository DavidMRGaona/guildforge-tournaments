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
 * A draft tournament is not public yet: its pages answer as if it did not exist, by slug or id,
 * and its event's page leaves it out. A cancelled one stays reachable, shown as cancelled.
 */
final class DraftTournamentPagesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

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
    public function test_a_draft_tournament_page_is_not_found_by_slug(string $routeName): void
    {
        $tournament = $this->tournament(TournamentStatus::Draft);

        $this->get(route($routeName, $tournament->slug))->assertNotFound();
    }

    #[DataProvider('tournamentPages')]
    public function test_a_draft_tournament_page_is_not_found_by_id(string $routeName): void
    {
        $tournament = $this->tournament(TournamentStatus::Draft);

        $this->get(route($routeName, $tournament->id))->assertNotFound();
    }

    public function test_a_draft_tournament_takes_no_check_in(): void
    {
        $tournament = $this->tournament(TournamentStatus::Draft);

        $this->post(route('tournaments.check-in.store', $tournament->slug), ['email' => 'guest@example.com', 'gdpr_consent' => '1'])
            ->assertNotFound();
    }

    public function test_the_event_page_leaves_out_a_draft_tournament(): void
    {
        $tournament = $this->tournament(TournamentStatus::Draft);
        $event = EventModel::query()->findOrFail($tournament->event_id);

        $this->get(route('events.show', $event->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tournament', null))
            ->assertDontSee($tournament->slug);
    }

    #[DataProvider('tournamentPages')]
    public function test_a_cancelled_tournament_page_still_opens(string $routeName): void
    {
        $tournament = $this->tournament(TournamentStatus::Cancelled);

        $this->get(route($routeName, $tournament->slug))->assertOk();
    }

    private function tournament(TournamentStatus $status): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addDays(3)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => $status,
            'allow_guests' => true,
            'result_reporting' => 'admin_only',
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
        ]);
    }
}
