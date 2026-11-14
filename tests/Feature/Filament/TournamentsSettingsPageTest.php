<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Application\Authorization\Services\PermissionRegistryInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Application\Services\SettingsServiceInterface;
use App\Filament\Clusters\SettingsCluster;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\SettingModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Tournaments\Filament\Pages\TournamentsSettings;
use Modules\Tournaments\TournamentsServiceProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The settings live on the module's own page in the settings cluster, behind a permission of the module.
 */
final class TournamentsSettingsPageTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string PERMISSION = 'tournaments:tournaments.manage_settings';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([], [TournamentsSettings::class]);
    }

    public function test_the_provider_declares_the_page_and_registers_it(): void
    {
        $provider = new TournamentsServiceProvider(app());

        $this->assertSame(TournamentsSettings::class, $provider->getSettingsPage());
        $this->assertContains(TournamentsSettings::class, $provider->registerFilamentPages());
        $this->assertSame([], $provider->getSettingsSchema());
    }

    public function test_the_page_is_in_the_settings_cluster_with_its_slot(): void
    {
        $this->assertSame(SettingsCluster::class, TournamentsSettings::getCluster());
        $this->assertSame(180, TournamentsSettings::getNavigationSort());
        $this->assertSame('Torneos', TournamentsSettings::getNavigationLabel());
    }

    public function test_the_permission_is_registered_and_never_granted_by_default(): void
    {
        $this->assertTrue(app(PermissionRegistryInterface::class)->has(self::PERMISSION));

        $permission = collect((new TournamentsServiceProvider(app()))->registerPermissions())
            ->first(fn ($dto): bool => $dto->name === 'tournaments.manage_settings');

        $this->assertNotNull($permission);
        $this->assertSame([], $permission->roles);
    }

    public function test_access_needs_the_module_permission(): void
    {
        $this->actingAs($this->userWith([]));
        $this->assertFalse(TournamentsSettings::canAccess());

        $this->actingAs($this->userWith(['modules.configure']));
        $this->assertFalse(TournamentsSettings::canAccess());

        $this->actingAs($this->userWith([self::PERMISSION]));
        $this->assertTrue(TournamentsSettings::canAccess());
    }

    public function test_saving_without_the_permission_is_refused(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->userWith([self::PERMISSION]));
        $component = Livewire::test(TournamentsSettings::class);

        $this->actingAs($this->userWith([]));

        $component->call('save')->assertForbidden();

        $this->assertNull(SettingModel::query()->where('key', 'module_settings:tournaments')->first());
    }

    public function test_values_saved_before_load_on_the_page_and_round_trip_through_the_same_row(): void
    {
        // Booted settings come from config; the stored row is merged over the shipped defaults at boot
        app(SettingsServiceInterface::class)->set('module_settings:tournaments', json_encode([
            'default_result_reporting' => 'players_trusted',
            'default_check_in_starts_before' => 45,
            'guest_rate_limit' => ['per_minute' => 7, 'per_hour' => 30],
            'reminders' => ['enabled' => true, 'hours_before' => 12],
        ], JSON_THROW_ON_ERROR));
        config()->set('modules.settings.tournaments', app(ModuleSettingsStoreInterface::class)->effective('tournaments', base_path('modules/tournaments')));

        $this->actingAs($this->userWith([self::PERMISSION]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(TournamentsSettings::class)
            ->assertFormSet([
                'default_result_reporting' => 'players_trusted',
                'default_check_in_starts_before' => 45,
                'guest_rate_limit.per_minute' => 7,
                'reminders.hours_before' => 12,
            ])
            ->fillForm(['default_check_in_starts_before' => 50])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = json_decode(
            (string) SettingModel::query()->where('key', 'module_settings:tournaments')->value('value'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('players_trusted', $stored['default_result_reporting']);
        $this->assertEquals(50, $stored['default_check_in_starts_before']);
        $this->assertSame(['enabled' => true, 'hours_before' => 12], $stored['reminders']);
    }

    /**
     * @param  array<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::create([
            'name' => 'tester_'.uniqid(),
            'display_name' => 'Permissions tester',
        ]);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'tournaments', 'action' => 'manage_settings', 'module' => 'tournaments'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
