<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Database;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Since 2.4.0 recording results and managing participants need their own permissions:
 * every role that could edit tournaments gets both on update, so nobody loses an action.
 */
final class GrantResultsAndParticipantsPermissionsTest extends ModuleTestCase
{
    private const string UPDATE = 'tournaments:tournaments.update';

    private const string REPORT_RESULTS = 'tournaments:tournaments.report_results';

    private const string MANAGE_PARTICIPANTS = 'tournaments:tournaments.manage_participants';

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    /** The migration is an anonymous class: there is no type to name its up() and down() with. */
    private mixed $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_15_000001_grant_results_and_participants_permissions_to_tournament_editors.php';

        // Enabling the module already ran the migration; start from a site that never had it
        DB::table('permissions')->whereIn('key', [self::REPORT_RESULTS, self::MANAGE_PARTICIPANTS])->delete();
    }

    public function test_it_creates_the_permissions_like_permissions_sync_does(): void
    {
        $this->migration->up();

        foreach ([self::REPORT_RESULTS => ['report_results', 'Reportar resultados'], self::MANAGE_PARTICIPANTS => ['manage_participants', 'Gestionar participantes']] as $key => [$action, $label]) {
            $row = DB::table('permissions')->where('key', $key)->first();
            $this->assertNotNull($row, $key);
            $this->assertTrue(Str::isUuid($row->id));
            $this->assertSame($label, $row->label);
            $this->assertSame('tournaments', $row->resource);
            $this->assertSame($action, $row->action);
            $this->assertSame('tournaments', $row->module);
            $this->assertNotNull($row->created_at);
        }
    }

    public function test_permissions_sync_keeps_the_rows_the_migration_created(): void
    {
        $this->migration->up();
        $created = $this->rows();

        $this->assertSame(0, Artisan::call('permissions:sync'));

        $this->assertSame($created, $this->rows());
    }

    public function test_it_grants_both_permissions_to_every_role_that_edits_tournaments(): void
    {
        $editors = [$this->roleWith([self::UPDATE]), $this->roleWith([self::UPDATE, 'tournaments:tournaments.view_any'])];
        $viewer = $this->roleWith(['tournaments:tournaments.view_any']);

        $this->migration->up();

        foreach ($editors as $role) {
            $this->assertSame([self::MANAGE_PARTICIPANTS, self::REPORT_RESULTS], $this->grantedNewPermissions($role));
        }
        $this->assertSame([], $this->grantedNewPermissions($viewer));
    }

    public function test_it_reuses_existing_permission_rows_and_grants(): void
    {
        $this->assertSame(0, Artisan::call('permissions:sync'));
        $ids = DB::table('permissions')->whereIn('key', [self::REPORT_RESULTS, self::MANAGE_PARTICIPANTS])->orderBy('key')->pluck('id')->all();
        $this->assertCount(2, $ids, 'permissions:sync must know the module permissions');
        $role = $this->roleWith([self::UPDATE, self::REPORT_RESULTS]);

        $this->migration->up();

        $this->assertSame($ids, DB::table('permissions')->whereIn('key', [self::REPORT_RESULTS, self::MANAGE_PARTICIPANTS])->orderBy('key')->pluck('id')->all());
        $this->assertSame([self::MANAGE_PARTICIPANTS, self::REPORT_RESULTS], $this->grantedNewPermissions($role));
    }

    public function test_running_it_twice_leaves_one_row_and_one_grant_each(): void
    {
        $role = $this->roleWith([self::UPDATE]);

        $this->migration->up();
        $this->migration->up();

        $this->assertSame(1, DB::table('permissions')->where('key', self::REPORT_RESULTS)->count());
        $this->assertSame(1, DB::table('permissions')->where('key', self::MANAGE_PARTICIPANTS)->count());
        $this->assertSame([self::MANAGE_PARTICIPANTS, self::REPORT_RESULTS], $this->grantedNewPermissions($role));
    }

    public function test_a_site_where_no_role_edits_tournaments_grants_nothing(): void
    {
        $viewer = $this->roleWith(['tournaments:tournaments.view_any']);

        $this->migration->up();

        $this->assertSame(0, DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('permissions.key', [self::REPORT_RESULTS, self::MANAGE_PARTICIPANTS])
            ->count());
        $this->assertSame([], $this->grantedNewPermissions($viewer));
    }

    public function test_editors_keep_reporting_results_and_managing_participants_right_after_it_runs(): void
    {
        $user = UserModel::factory()->create();
        $user->roles()->attach($this->roleWith([self::UPDATE])->id);
        $authorization = $this->app->make(AuthorizationServiceInterface::class);
        // Cached before the update, as on a running site
        $this->assertFalse($authorization->can($user, self::REPORT_RESULTS));
        $this->assertFalse($authorization->can($user, self::MANAGE_PARTICIPANTS));

        $this->migration->up();

        $this->assertTrue($authorization->can($user, self::REPORT_RESULTS));
        $this->assertTrue($authorization->can($user, self::MANAGE_PARTICIPANTS));
    }

    public function test_down_changes_nothing(): void
    {
        $role = $this->roleWith([self::UPDATE]);
        $this->migration->up();

        $this->migration->down();

        $this->assertSame(2, DB::table('permissions')->whereIn('key', [self::REPORT_RESULTS, self::MANAGE_PARTICIPANTS])->count());
        $this->assertSame([self::MANAGE_PARTICIPANTS, self::REPORT_RESULTS], $this->grantedNewPermissions($role));
    }

    /**
     * @param  array<string>  $permissionKeys
     */
    private function roleWith(array $permissionKeys): RoleModel
    {
        $role = RoleModel::create(['name' => 'role_'.uniqid(), 'display_name' => 'Role']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'tournaments', 'action' => Str::afterLast($key, '.'), 'module' => 'tournaments'],
            );
            $role->permissions()->attach($permission->id);
        }

        return $role;
    }

    /**
     * @return list<string>
     */
    private function grantedNewPermissions(RoleModel $role): array
    {
        return array_values(DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $role->id)
            ->whereIn('permissions.key', [self::REPORT_RESULTS, self::MANAGE_PARTICIPANTS])
            ->orderBy('permissions.key')
            ->pluck('permissions.key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = [];
        foreach (DB::table('permissions')->whereIn('key', [self::REPORT_RESULTS, self::MANAGE_PARTICIPANTS])->get() as $row) {
            $row = (array) $row;
            unset($row['updated_at']);
            $rows[(string) $row['key']] = $row;
        }
        ksort($rows);

        return $rows;
    }
}
