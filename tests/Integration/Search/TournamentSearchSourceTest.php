<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Search;

use App\Application\Search\Services\SearchSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Search\Persistence\Eloquent\Models\SearchDocumentModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Search\TournamentSearchSource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Tournaments in the site search: the tournaments of the public listing (neither drafts nor cancelled),
 * finished ones included and marked, kept up to date on save. The test queue is "sync", so the
 * after-commit refresh runs inside the test.
 */
final class TournamentSearchSourceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentSearchSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->source = $this->app->make(TournamentSearchSource::class);
    }

    public function test_the_registry_offers_tournaments_with_their_labels(): void
    {
        $source = null;
        foreach ($this->app->make(SearchSourceRegistryInterface::class)->indexedSources() as $candidate) {
            if ($candidate->type() === 'tournaments.tournaments') {
                $source = $candidate;
            }
        }

        $this->assertInstanceOf(TournamentSearchSource::class, $source);
        $this->assertSame('Torneos', $source->label());
        $this->assertSame('Torneo', $source->itemLabel());
        $this->assertSame('Finalizado', $source->pastLabel());
    }

    public function test_draft_and_cancelled_tournaments_have_no_document(): void
    {
        $draft = $this->tournament(['status' => 'draft']);
        $cancelled = $this->tournament(['status' => 'cancelled']);

        $this->assertNull($this->source->document($draft->id));
        $this->assertNull($this->source->document($cancelled->id));
        $this->assertNotContains($draft->id, $this->documentIds());
        $this->assertNotContains($cancelled->id, $this->documentIds());
    }

    public function test_every_listed_tournament_has_a_document(): void
    {
        $ids = [];
        foreach (['registration_open', 'registration_closed', 'in_progress', 'finished'] as $status) {
            $ids[] = $this->tournament(['status' => $status])->id;
        }

        $this->assertEqualsCanonicalizing($ids, $this->documentIds());
    }

    public function test_the_document_has_the_event_dates_place_and_game(): void
    {
        $profile = $this->gameProfile();
        $event = $this->event([
            'title' => 'Jornadas de ejemplo',
            'location' => 'Local de la asociación',
            'start_date' => new DateTimeImmutable('2026-12-12T09:00:00Z'),
            'end_date' => new DateTimeImmutable('2026-12-12T20:00:00Z'),
        ]);
        $tournament = $this->tournament([
            'event_id' => $event->id,
            'name' => 'Torneo de invierno',
            'slug' => 'torneo-de-invierno',
            'description' => "Cinco rondas\ncon <b>suizo</b>",
            'game_profile_id' => $profile->id,
        ]);

        $document = $this->source->document($tournament->id);

        $this->assertNotNull($document);
        $this->assertSame('Torneo de invierno', $document->title);
        $this->assertSame('Cinco rondas con suizo', $document->body);
        $this->assertSame('/torneos/torneo-de-invierno', $document->url);
        $this->assertSame('Juego de ejemplo', $document->keywords);
        $this->assertSame('Jornadas de ejemplo · Local de la asociación', $document->meta);
        $this->assertSame('2026-12-12T09:00:00+00:00', $document->startsAt?->format(DATE_ATOM));
        $this->assertSame('2026-12-12T20:00:00+00:00', $document->endsAt?->format(DATE_ATOM));
        $this->assertFalse($document->finished);
    }

    public function test_the_image_falls_back_to_the_event_one(): void
    {
        $event = $this->event(['image_public_id' => 'events/example.jpg']);

        $this->assertSame('events/example.jpg', $this->source->document($this->tournament(['event_id' => $event->id])->id)?->imagePublicId);
        $this->assertSame('tournaments/own.jpg', $this->source->document($this->tournament(['image_public_id' => 'tournaments/own.jpg'])->id)?->imagePublicId);
    }

    public function test_a_finished_tournament_is_marked_as_finished(): void
    {
        $this->assertTrue($this->source->document($this->tournament(['status' => 'finished'])->id)?->finished);
    }

    public function test_ids_that_are_not_uuids_or_unknown_have_no_document(): void
    {
        $this->assertNull($this->source->document('not-a-uuid'));
        $this->assertNull($this->source->document((string) Str::uuid()));
    }

    public function test_publishing_a_tournament_stores_its_document(): void
    {
        $tournament = $this->tournament(['status' => 'draft']);
        $this->assertNull($this->stored($tournament->id));

        $tournament->update(['status' => 'registration_open']);

        $stored = SearchDocumentModel::query()->where('type', 'tournaments.tournaments')->where('source_id', $tournament->id)->first();
        $this->assertSame('Torneo de ejemplo', $stored?->title);
    }

    public function test_cancelling_a_tournament_forgets_it_on_save(): void
    {
        $tournament = $this->tournament();
        $this->assertNotNull($this->stored($tournament->id));

        $tournament->update(['status' => 'cancelled']);

        $this->assertNull($this->stored($tournament->id));
    }

    public function test_deleting_a_tournament_forgets_its_document(): void
    {
        $tournament = $this->tournament();

        $tournament->delete();

        $this->assertNull($this->stored($tournament->id));
    }

    public function test_deleting_the_event_forgets_its_tournament(): void
    {
        $event = $this->event();
        $tournament = $this->tournament(['event_id' => $event->id]);

        $event->delete();

        $this->assertNull($this->stored($tournament->id));
    }

    public function test_changing_the_event_refreshes_its_tournament(): void
    {
        $event = $this->event();
        $tournament = $this->tournament(['event_id' => $event->id]);

        $event->update(['title' => 'Jornadas renombradas', 'start_date' => new DateTimeImmutable('2027-01-09T10:00:00Z')]);

        $this->assertStringStartsWith('Jornadas renombradas', (string) $this->stored($tournament->id)?->meta);
        $this->assertSame('2027-01-09 10:00:00', $this->stored($tournament->id)?->starts_at?->format('Y-m-d H:i:s'));
    }

    public function test_renaming_the_game_profile_refreshes_its_tournaments(): void
    {
        $profile = $this->gameProfile();
        $tournament = $this->tournament(['game_profile_id' => $profile->id]);

        $profile->update(['name' => 'Juego renombrado']);

        $this->assertSame('Juego renombrado', $this->stored($tournament->id)?->keywords);
    }

    /**
     * @return list<string>
     */
    private function documentIds(): array
    {
        $ids = [];

        foreach ($this->source->documents() as $document) {
            $ids[] = $document->id;
        }

        return $ids;
    }

    private function stored(string $id): ?SearchDocumentModel
    {
        return SearchDocumentModel::query()->where('type', 'tournaments.tournaments')->where('source_id', $id)->first();
    }

    private function gameProfile(): GameProfileModel
    {
        return GameProfileModel::create([
            'name' => 'Juego de ejemplo',
            'slug' => 'juego-de-ejemplo',
            'stat_definitions' => [],
            'scoring_rules' => [],
            'tiebreaker_config' => [],
            'pairing_config' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): EventModel
    {
        return EventModel::factory()->published()->create(array_merge([
            'title' => 'Jornadas de ejemplo',
            'location' => null,
            'start_date' => new DateTimeImmutable('2026-12-12T09:00:00Z'),
            'end_date' => new DateTimeImmutable('2026-12-12T20:00:00Z'),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create(array_merge([
            'event_id' => $this->event()->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => 'registration_open',
            'result_reporting' => 'admin_only',
        ], $attributes));
    }
}
