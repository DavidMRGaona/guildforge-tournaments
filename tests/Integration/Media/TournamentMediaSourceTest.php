<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Media;

use App\Application\Media\Contracts\MediaUsageSourceInterface;
use App\Application\Media\Services\MediaSourceRegistryInterface;
use App\Domain\Media\ValueObjects\MediaFileId;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Media\TournamentMediaSource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
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

    protected function makeRecord(?string $path): Model
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->published()->create()->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-'.Str::lower(Str::random(8)),
            'status' => 'registration_open',
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
