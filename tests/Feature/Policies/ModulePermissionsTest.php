<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Policies;

use App\Application\Authorization\Services\PermissionRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\GameProfileResource\Pages\ListGameProfiles;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\ListTournaments;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A role granted a module permission from the panel must open what the permission names.
 */
final class ModulePermissionsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_core_registers_the_permissions_under_the_module_prefix(): void
    {
        $registry = app(PermissionRegistryInterface::class);

        foreach (['view_any', 'view', 'create', 'update', 'delete', 'manage_config'] as $action) {
            $this->assertTrue($registry->has("tournaments:tournaments.{$action}"), $action);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function tournamentAbilities(): array
    {
        return [
            'viewAny' => ['viewAny', 'tournaments:tournaments.view_any'],
            'view' => ['view', 'tournaments:tournaments.view'],
            'create' => ['create', 'tournaments:tournaments.create'],
            'update' => ['update', 'tournaments:tournaments.update'],
            'delete' => ['delete', 'tournaments:tournaments.delete'],
            'deleteAny' => ['deleteAny', 'tournaments:tournaments.delete'],
        ];
    }

    #[DataProvider('tournamentAbilities')]
    public function test_a_granted_tournament_permission_allows_the_ability(string $ability, string $permission): void
    {
        $tournament = $this->tournament();
        $arguments = in_array($ability, ['viewAny', 'create', 'deleteAny'], true) ? [TournamentModel::class] : [$tournament];

        $this->assertTrue($this->userWith([$permission])->can($ability, $arguments));
        $this->assertFalse($this->userWith([])->can($ability, $arguments));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function gameProfileAbilities(): array
    {
        return [
            'viewAny' => ['viewAny'],
            'view' => ['view'],
            'create' => ['create'],
            'update' => ['update'],
            'delete' => ['delete'],
        ];
    }

    #[DataProvider('gameProfileAbilities')]
    public function test_the_manage_config_permission_allows_the_game_profile_ability(string $ability): void
    {
        $profile = $this->customProfile();
        $arguments = in_array($ability, ['viewAny', 'create'], true) ? [GameProfileModel::class] : [$profile];

        $this->assertTrue($this->userWith(['tournaments:tournaments.manage_config'])->can($ability, $arguments));
        $this->assertFalse($this->userWith([])->can($ability, $arguments));
    }

    public function test_an_editor_granted_view_any_opens_the_tournament_list(): void
    {
        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);

        $this->actingAs($this->userWith(['tournaments:tournaments.view_any']));
        Livewire::test(ListTournaments::class)->assertOk();
    }

    public function test_an_editor_without_the_permission_cannot_open_the_tournament_list(): void
    {
        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);

        $this->actingAs($this->userWith([]));
        Livewire::test(ListTournaments::class)->assertForbidden();
    }

    public function test_an_editor_granted_manage_config_opens_the_game_profile_list(): void
    {
        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);

        $this->actingAs($this->userWith(['tournaments:tournaments.manage_config']));
        Livewire::test(ListGameProfiles::class)->assertOk();
    }

    /**
     * An editor (panel access) with an extra role holding exactly the given permission keys.
     *
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
                ['label' => $key, 'resource' => 'tournaments', 'action' => Str::afterLast($key, '.'), 'module' => 'tournaments'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-de-prueba',
            'status' => 'draft',
            'result_reporting' => 'admin_only',
        ])->refresh();
    }

    private function customProfile(): GameProfileModel
    {
        $system = GameProfileModel::query()->where('is_system', true)->firstOrFail();
        $custom = $system->replicate(['id', 'slug']);
        $custom->name = 'Perfil propio';
        $custom->is_system = false;
        $custom->save();

        return $custom;
    }
}
