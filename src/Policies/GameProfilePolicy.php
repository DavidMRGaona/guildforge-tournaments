<?php

declare(strict_types=1);

namespace Modules\Tournaments\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

final class GameProfilePolicy
{
    use AuthorizesWithPermissions;

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments.manage_config');
    }

    public function view(UserModel $user, GameProfileModel $gameProfile): bool
    {
        return $this->authorize($user, 'tournaments.manage_config');
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments.manage_config');
    }

    public function update(UserModel $user, GameProfileModel $gameProfile): bool
    {
        return $this->authorize($user, 'tournaments.manage_config');
    }

    public function delete(UserModel $user, GameProfileModel $gameProfile): bool
    {
        // Cannot delete system profiles
        if ($gameProfile->is_system) {
            return false;
        }

        return $this->authorize($user, 'tournaments.manage_config');
    }

    public function deleteAny(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments.manage_config');
    }

    public function restore(UserModel $user, GameProfileModel $gameProfile): bool
    {
        return $this->authorize($user, 'tournaments.manage_config');
    }

    public function forceDelete(UserModel $user, GameProfileModel $gameProfile): bool
    {
        // Cannot force delete system profiles
        if ($gameProfile->is_system) {
            return false;
        }

        return $this->authorize($user, 'tournaments.manage_config');
    }
}
