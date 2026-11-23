<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Media;

use App\Application\Media\Contracts\MediaUsageSourceInterface;
use App\Application\Media\Contracts\ShowsMediaPreviews;
use App\Application\Media\Services\MediaSourceRegistryInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Media\TournamentMediaSource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Media\ColumnSourceContractTests;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Tournament images in the panel media library: the shared contract of column sources plus what is
 * specific to tournaments. Tournaments have no trash, so deleting one ends its use of the file.
 */
final class TournamentMediaSourceTest extends ModuleTestCase
{
    use ColumnSourceContractTests;
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([TournamentResource::class]);
    }

    protected function source(): MediaUsageSourceInterface
    {
        return $this->app->make(TournamentMediaSource::class);
    }

    protected function makeRecord(?string $path, string $status = 'registration_open'): Model
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->published()->create()->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => $status,
            'result_reporting' => 'admin_only',
            'image_public_id' => $path,
        ]);
    }

    protected function updatePermission(): string
    {
        return 'tournaments:tournaments.update';
    }

    public function test_declares_its_type_label_and_folders(): void
    {
        $this->assertSame('tournaments.tournaments', $this->source()->type());
        $this->assertSame('Torneos', $this->source()->label());
        $this->assertSame(['tournaments/'], $this->source()->folders());
    }

    public function test_usage_carries_the_tournament_name_and_role(): void
    {
        $tournament = $this->makeRecord('tournaments/2026/a.jpg');

        $usage = $this->source()->usagesFor((string) $tournament->getKey())[0];

        $this->assertSame('Torneo de ejemplo', $usage->title);
        $this->assertSame('Imagen', $usage->role);
    }

    public function test_shows_its_previews_in_the_media_library(): void
    {
        $this->assertInstanceOf(ShowsMediaPreviews::class, $this->source());
    }

    public function test_a_draft_tournament_hides_its_image_under_a_neutral_title(): void
    {
        // Only its organisers know a draft exists: neither its image nor its name may show
        $tournament = $this->makeRecord('tournaments/2026/a.jpg', TournamentStatus::Draft->value);

        $usage = $this->source()->usagesFor((string) $tournament->getKey())[0];

        $this->assertTrue($usage->hidden);
        $this->assertSame('Torneo sin publicar', $usage->title);
        $this->assertSame('Imagen', $usage->role);
    }

    public function test_the_full_listing_hides_drafts_too(): void
    {
        $this->makeRecord('tournaments/2026/a.jpg', TournamentStatus::Draft->value);
        $this->makeRecord('tournaments/2026/b.jpg');

        $hidden = [];
        foreach ($this->source()->usages() as $usage) {
            $hidden[$usage->storedPath] = $usage->hidden;
        }

        $this->assertSame(['tournaments/2026/a.jpg' => true, 'tournaments/2026/b.jpg' => false], $hidden);
    }

    /**
     * @return array<string, array{TournamentStatus}>
     */
    public static function shownStatuses(): array
    {
        return [
            'registration open' => [TournamentStatus::RegistrationOpen],
            'registration closed' => [TournamentStatus::RegistrationClosed],
            'in progress' => [TournamentStatus::InProgress],
            'finished' => [TournamentStatus::Finished],
            // Its public page stays open once cancelled
            'cancelled' => [TournamentStatus::Cancelled],
        ];
    }

    #[DataProvider('shownStatuses')]
    public function test_a_tournament_past_its_draft_shows_its_image_and_name(TournamentStatus $status): void
    {
        $tournament = $this->makeRecord('tournaments/2026/a.jpg', $status->value);

        $usage = $this->source()->usagesFor((string) $tournament->getKey())[0];

        $this->assertFalse($usage->hidden);
        $this->assertSame('Torneo de ejemplo', $usage->title);
    }

    public function test_a_draft_still_counts_as_using_its_file(): void
    {
        $this->makeRecord('tournaments/2026/a.jpg', TournamentStatus::Draft->value);

        $this->assertTrue($this->source()->usesFile(new MediaFileId('tournaments/2026/a')));
    }

    public function test_the_edit_link_of_a_draft_still_follows_the_policy(): void
    {
        $tournament = $this->makeRecord('tournaments/a.jpg', TournamentStatus::Draft->value);

        $editor = $this->editorWithPermissions(['tournaments:tournaments.update']);
        $other = $this->editorWithPermissions([]);

        $this->assertStringEndsWith('/edit', (string) $this->source()->editUrl((string) $tournament->getKey(), $editor));
        $this->assertNull($this->source()->editUrl((string) $tournament->getKey(), $other));
    }

    public function test_a_deleted_tournament_no_longer_uses_the_file(): void
    {
        $tournament = $this->makeRecord('tournaments/2026/a.jpg');
        $this->assertTrue($this->source()->usesFile(new MediaFileId('tournaments/2026/a')));

        $tournament->delete();

        $this->assertFalse($this->source()->usesFile(new MediaFileId('tournaments/2026/a')));
        $this->assertSame([], iterator_to_array($this->source()->usages(), false));
    }

    public function test_edit_url_points_to_the_tournament_edit_page(): void
    {
        $tournament = $this->makeRecord('tournaments/a.jpg');
        $user = $this->editorWithPermissions(['tournaments:tournaments.update']);

        $url = $this->source()->editUrl((string) $tournament->getKey(), $user);

        $this->assertStringContainsString((string) $tournament->getKey(), (string) $url);
        $this->assertStringEndsWith('/edit', (string) $url);
    }

    public function test_the_module_registers_the_source_once(): void
    {
        $types = [];
        foreach ($this->app->make(MediaSourceRegistryInterface::class)->sources() as $source) {
            $types[] = $source->type();
        }

        $this->assertSame(1, count(array_keys($types, 'tournaments.tournaments', true)));
    }
}
