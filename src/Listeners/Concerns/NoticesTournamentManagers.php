<?php

declare(strict_types=1);

namespace Modules\Tournaments\Listeners\Concerns;

use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use Modules\Tournaments\Notifications\TournamentManagementNotification;

/**
 * Puts a management notice in the bell of every account that holds its permission, on top of
 * the mail to the tournament's address. Needs a PermissionHolderFinder in $this->managers.
 */
trait NoticesTournamentManagers
{
    private function noticeManagers(TournamentManagementNotification $notice): void
    {
        // As authorization sees them: a paused role does not count. Checked again on delivery
        foreach ($this->managers->usersWhoCan($notice->permission()) as $manager) {
            $manager->notify($notice);
        }
    }
}
