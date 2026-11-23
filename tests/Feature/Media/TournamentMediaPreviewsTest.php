<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Media;

use App\Application\Media\Services\MediaCatalogInterface;
use App\Application\Media\Services\MediaIndexerInterface;
use App\Infrastructure\Media\Persistence\Eloquent\Models\MediaFileModel;
use App\Infrastructure\Media\Services\MediaLibraryQueryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Media\InMemoryMediaCatalog;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What the panel media library shows of the tournament images once synced (core#837): every image past
 * its draft keeps its thumbnail and can be chosen again; a draft's is concealed and never offered.
 */
final class TournamentMediaPreviewsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private InMemoryMediaCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('images');
        $this->catalog = new InMemoryMediaCatalog;
        $this->app->instance(MediaCatalogInterface::class, $this->catalog);
    }

    public function test_the_image_of_a_tournament_past_its_draft_shows_and_can_be_chosen(): void
    {
        $this->tournament('tournaments/2026/11/open.jpg', TournamentStatus::RegistrationOpen);
        $this->catalog->add('tournaments/2026/11/open');
        $this->sync();

        $this->assertFalse($this->library()->conceals($this->file('tournaments/2026/11/open')));
        $this->assertSame(['tournaments/2026/11/open'], $this->reusable());
    }

    public function test_the_image_of_a_draft_tournament_is_concealed_and_not_offered(): void
    {
        $this->tournament('tournaments/2026/11/draft.jpg', TournamentStatus::Draft);
        $this->catalog->add('tournaments/2026/11/draft');
        $this->sync();

        $this->assertTrue($this->library()->conceals($this->file('tournaments/2026/11/draft')));
        $this->assertSame([], $this->reusable());
    }

    public function test_an_image_shared_with_a_draft_is_concealed_for_both(): void
    {
        $this->tournament('tournaments/2026/11/shared.jpg', TournamentStatus::Finished);
        $this->tournament('tournaments/2026/11/shared.jpg', TournamentStatus::Draft);
        $this->catalog->add('tournaments/2026/11/shared');
        $this->sync();

        $this->assertTrue($this->library()->conceals($this->file('tournaments/2026/11/shared')));
    }

    private function tournament(string $image, TournamentStatus $status): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->published()->create()->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => $status->value,
            'result_reporting' => 'admin_only',
            'image_public_id' => $image,
        ]);
    }

    private function sync(): void
    {
        $result = $this->app->make(MediaIndexerInterface::class)->sync();

        $this->assertSame([], $result->failedSources);
    }

    private function library(): MediaLibraryQueryInterface
    {
        return $this->app->make(MediaLibraryQueryInterface::class);
    }

    private function file(string $fileId): MediaFileModel
    {
        return MediaFileModel::query()->where('file_id', $fileId)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function reusable(): array
    {
        $library = $this->library();

        /** @var list<string> */
        return $library->applyReusable($library->query())->pluck('file_id')->all();
    }
}
