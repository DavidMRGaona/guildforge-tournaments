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
        return $this->authorize($user, 'tournaments:tournaments.view_any');
    }

    public function view(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.view');
    }

    public function create(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.create');
    }

    public function update(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.update');
    }

    /**
     * Record match results in the panel (rounds and matches managers).
     */
    public function reportResults(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.report_results');
    }

    /**
     * Add, confirm, check in, withdraw, disqualify and delete participants in the panel.
     */
    public function manageParticipants(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.manage_participants');
    }

    public function delete(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.delete');
    }

    public function deleteAny(UserModel $user): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.delete');
    }

    public function restore(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.delete');
    }

    public function forceDelete(UserModel $user, TournamentModel $tournament): bool
    {
        return $this->authorize($user, 'tournaments:tournaments.delete');
    }
}
