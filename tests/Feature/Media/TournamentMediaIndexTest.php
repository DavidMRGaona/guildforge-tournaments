<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Media;

use App\Application\Media\Services\MediaCatalogInterface;
use App\Infrastructure\Media\Jobs\RefreshMediaUsagesJob;
use App\Infrastructure\Media\Persistence\Eloquent\Models\MediaUsageModel;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Media\InMemoryMediaCatalog;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The media usage index follows the tournaments as they are saved: a refresh is queued after the
 * commit, and running it rewrites the uses of that tournament.
 */
final class TournamentMediaIndexTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private InMemoryMediaCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        // Models remove replaced images from the disk when saved
        Storage::fake('images');
        $this->catalog = new InMemoryMediaCatalog;
        $this->app->instance(MediaCatalogInterface::class, $this->catalog);
    }

    private function tournament(?string $image): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->published()->create()->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => 'registration_open',
            'result_reporting' => 'admin_only',
            'image_public_id' => $image,
        ]);
    }

    private function assertRefreshQueued(TournamentModel $tournament, int $times): void
    {
        // The event of the tournament queues its own refreshes: only this source's are counted
        $queued = Bus::dispatched(RefreshMediaUsagesJob::class, fn (RefreshMediaUsagesJob $job): bool => $job->type === 'tournaments.tournaments'
            && $job->recordId === $tournament->id
            && $job->afterCommit === true);

        $this->assertCount($times, $queued);
    }

    public function test_saving_and_deleting_a_tournament_queue_a_refresh(): void
    {
        Bus::fake();

        $tournament = $this->tournament('tournaments/2026/a.jpg');
        $this->assertRefreshQueued($tournament, 1);

        $tournament->update(['name' => 'Otro nombre']);
        $this->assertRefreshQueued($tournament, 2);

        $tournament->delete();
        $this->assertRefreshQueued($tournament, 3);
    }

    public function test_the_refresh_rewrites_the_uses_of_the_tournament(): void
    {
        Bus::fake();
        $tournament = $this->tournament('tournaments/2026/a.jpg');
        $this->catalog->add('tournaments/2026/a', bytes: 4096);

        $this->app->call([new RefreshMediaUsagesJob('tournaments.tournaments', $tournament->id), 'handle']);

        $usage = MediaUsageModel::query()->where('source_type', 'tournaments.tournaments')->firstOrFail();
        $this->assertSame('tournaments/2026/a', $usage->file_id);
        $this->assertSame($tournament->id, $usage->record_id);
        $this->assertSame('Torneo de ejemplo', $usage->title);

        $tournament->delete();
        $this->app->call([new RefreshMediaUsagesJob('tournaments.tournaments', $tournament->id), 'handle']);

        $this->assertFalse(MediaUsageModel::query()->where('record_id', $tournament->id)->exists());
    }
}
