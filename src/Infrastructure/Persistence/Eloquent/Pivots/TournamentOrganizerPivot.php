<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A tournament's organizer. A pivot model, so that the panel's organizer list (a sync) raises
 * model events and the activity source hears who was added or removed (core#774).
 *
 * @property string $tournament_id
 * @property string $user_id
 */
final class TournamentOrganizerPivot extends Pivot
{
    protected $table = 'tournaments_organizers';

    public $incrementing = false;

    public $timestamps = false;
}
