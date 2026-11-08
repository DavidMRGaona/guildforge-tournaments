<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Support\PanelNavigationGroup;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\TournamentsServiceProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The sidebar groups belong to the core: the module names its entries' group with the enum's
 * label (a string) and takes the slot reserved for it, so it depends on no other module's numbers.
 */
final class PanelNavigationTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_tournaments_sit_in_the_activities_group_in_their_own_slot(): void
    {
        $this->assertSame(PanelNavigationGroup::Activities->getLabel(), TournamentResource::getNavigationGroup());
        $this->assertSame(20, TournamentResource::getNavigationSort());
        $this->assertSame('Torneos', TournamentResource::getNavigationLabel());
        $this->assertNull(TournamentResource::getNavigationParentItem());
    }

    public function test_tournament_profiles_sit_in_the_catalogues_group_in_their_own_slot(): void
    {
        $this->assertSame(PanelNavigationGroup::Catalogues->getLabel(), GameProfileResource::getNavigationGroup());
        $this->assertSame(40, GameProfileResource::getNavigationSort());
        $this->assertSame('Perfiles de torneo', GameProfileResource::getNavigationLabel());
        $this->assertNull(GameProfileResource::getNavigationParentItem());
    }

    public function test_the_module_declares_no_navigation_group_of_its_own(): void
    {
        $provider = new TournamentsServiceProvider($this->app);

        $this->assertSame([], $provider->registerNavigationGroups());
    }
}
