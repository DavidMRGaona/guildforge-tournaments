<?php

declare(strict_types=1);

namespace Modules\Tournaments\Policies;

use App\Infrastructure\Authorization\Policies\AuthorizesWithPermissions;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final class TournamentPolicy
{
    use AuthorizesWithPermissions;

    public function viewAny(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments.view_any');
    }

    public function view(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments.view');
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments.create');
    }

    public function update(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments.update');
    }

    public function delete(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments.delete');
    }

    public function deleteAny(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments.delete');
    }

    public function restore(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments.delete');
    }

    public function forceDelete(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments.delete');
    }
}
