<?php

declare(strict_types=1);

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Domain\Authorization\ValueObjects\PermissionId;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;

/**
 * From 2.4.0 the panel checks tournaments.report_results (recording results) and
 * tournaments.manage_participants (managing participants); before, tournaments.update was
 * enough. Every role holding tournaments.update gets both, so nobody loses an action.
 *
 * Module updates run migrations but not permissions:sync, so the permission rows may not
 * exist yet: they are created with the fields permissions:sync writes (which later finds
 * them by key and only refreshes their labels). A site where no role has
 * tournaments.update gets the rows and no grant.
 */
return new class extends Migration
{
    private const string UPDATE_KEY = 'tournaments:tournaments.update';

    /**
     * Key => action, translation key of the label and its fallback.
     * Keep the fallbacks equal to permissions.* in lang/es/messages.php.
     *
     * @var array<string, array{string, string, string}>
     */
    private const array PERMISSIONS = [
        'tournaments:tournaments.report_results' => ['report_results', 'tournaments::messages.permissions.report_results', 'Reportar resultados'],
        'tournaments:tournaments.manage_participants' => ['manage_participants', 'tournaments::messages.permissions.manage_participants', 'Gestionar participantes'],
    ];

    public function up(): void
    {
        $permissionIds = [];
        foreach (self::PERMISSIONS as $key => [$action, $labelKey, $fallback]) {
            $permissionIds[] = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                [
                    'id' => PermissionId::generate()->value,
                    'label' => $this->label($labelKey, $fallback),
                    'resource' => 'tournaments',
                    'action' => $action,
                    'module' => 'tournaments',
                ],
            )->id;
        }

        $editors = RoleModel::query()
            ->whereHas('permissions', static fn (Builder $query): Builder => $query->where('key', self::UPDATE_KEY))
            ->get();

        foreach ($editors as $role) {
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }

        // Role permissions were written directly: cached permission sets must not survive
        app(AuthorizationServiceInterface::class)->invalidateCache();
    }

    public function down(): void
    {
        // Nothing to undo: roles may have been edited since (an admin may have granted these
        // permissions by hand), and permission data is never deleted. 2.3.x never checks
        // these permissions, so the grants are harmless after going back to it.
    }

    private function label(string $labelKey, string $fallback): string
    {
        $label = __($labelKey);

        // The module's translations are not registered when it is migrated before it boots
        return is_string($label) && $label !== $labelKey ? $label : $fallback;
    }
};
