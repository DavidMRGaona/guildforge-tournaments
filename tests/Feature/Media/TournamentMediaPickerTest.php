<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Media;

use App\Application\Media\Contracts\ReleasesMediaThroughCoreInterface;
use App\Filament\Forms\Components\MediaLibraryPicker;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Infrastructure\Media\TournamentMediaSource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A tournament image can be chosen from the media library, so a file may be shared between records:
 * the source releases files through the core and the pages save inside one transaction.
 */
final class TournamentMediaPickerTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('images');
        $this->registerOnAdminPanel([TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_the_source_is_marked_as_releasing_through_the_core(): void
    {
        $this->assertContains(ReleasesMediaThroughCoreInterface::class, class_implements(TournamentMediaSource::class));
    }

    public function test_the_image_field_offers_the_library_when_creating(): void
    {
        $page = Livewire::test(CreateTournament::class);

        $this->assertOffersLibrary($page);
    }

    public function test_the_image_field_offers_the_library_when_editing(): void
    {
        $page = Livewire::test(EditTournament::class, ['record' => $this->tournament(null)->getRouteKey()]);

        $this->assertOffersLibrary($page);
    }

    public function test_the_pages_save_inside_one_transaction(): void
    {
        foreach ([CreateTournament::class, EditTournament::class] as $class) {
            $page = new \ReflectionClass($class);
            $property = $page->getProperty('hasDatabaseTransactions');

            $this->assertTrue($property->getDefaultValue(), $class);
        }
    }

    public function test_letting_go_of_a_shared_image_keeps_the_file_the_other_tournament_uses(): void
    {
        Storage::disk('images')->put('tournaments/2026/11/shared.jpg', 'image');
        $first = $this->tournament('tournaments/2026/11/shared.jpg');
        $this->tournament('tournaments/2026/11/shared.jpg');

        $first->update(['image_public_id' => null]);

        Storage::disk('images')->assertExists('tournaments/2026/11/shared.jpg');
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

    /**
     * @param  Testable<Component>  $page
     */
    private function assertOffersLibrary(Testable $page): void
    {
        $component = $page->instance();
        $this->assertInstanceOf(HasSchemas::class, $component);
        $form = $component->getSchema('form');
        $this->assertInstanceOf(Schema::class, $form);

        $field = null;
        foreach ($form->getFlatFields(withHidden: true) as $candidate) {
            if ($candidate instanceof FileUpload && $candidate->getName() === 'image_public_id') {
                $field = $candidate;
            }
        }
        $this->assertNotNull($field, 'The image field is missing');

        $page->assertActionVisible(TestAction::make(MediaLibraryPicker::ACTION)->schemaComponent(Str::after((string) $field->getKey(), 'form.'), schema: 'form'));
        $this->assertTrue($field->shouldPreventFilePathTampering());
    }
}
