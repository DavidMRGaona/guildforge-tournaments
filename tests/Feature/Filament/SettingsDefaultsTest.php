<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Filament\Resources\EventResource\Pages\EditEvent;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Livewire\Livewire;
use Modules\Tournaments\Filament\RelationManagers\TournamentConfigRelationManager;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\CreateTournament;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The module settings ("Valores por defecto") fill in the forms that create a tournament.
 */
final class SettingsDefaultsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const array CUSTOM_SETTINGS = [
        'default_result_reporting' => 'players_trusted',
        'default_allow_guests' => true,
        'default_requires_check_in' => true,
        'default_check_in_starts_before' => '45',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_the_create_form_starts_from_the_module_settings(): void
    {
        config()->set('modules.settings.tournaments', self::CUSTOM_SETTINGS);

        Livewire::test(CreateTournament::class)->assertFormSet([
            'result_reporting' => 'players_trusted',
            'allow_guests' => true,
            'requires_check_in' => true,
            'check_in_starts_before' => 45,
        ]);
    }

    public function test_the_create_form_falls_back_to_the_schema_defaults_without_settings(): void
    {
        config()->set('modules.settings.tournaments', []);

        Livewire::test(CreateTournament::class)->assertFormSet([
            'result_reporting' => 'admin_only',
            'allow_guests' => false,
            'requires_check_in' => false,
            'check_in_starts_before' => 30,
        ]);
    }

    public function test_the_event_tournament_tab_starts_from_the_module_settings(): void
    {
        config()->set('modules.settings.tournaments', self::CUSTOM_SETTINGS);
        $event = EventModel::factory()->create();

        Livewire::test(TournamentConfigRelationManager::class, ['ownerRecord' => $event, 'pageClass' => EditEvent::class])
            ->assertSet('configData.result_reporting', 'players_trusted')
            ->assertSet('configData.allow_guests', true)
            ->assertSet('configData.requires_check_in', true)
            ->assertSet('configData.check_in_starts_before', 45);
    }

    public function test_the_shipped_settings_match_the_settings_form_defaults(): void
    {
        $shipped = require dirname(__DIR__, 3).'/config/settings.php';

        $this->assertEquals([
            'default_result_reporting' => 'admin_only',
            'default_allow_guests' => false,
            'default_requires_check_in' => false,
            'default_check_in_starts_before' => 30,
        ], $shipped);
    }
}
