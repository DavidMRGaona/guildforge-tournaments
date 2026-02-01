<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tournament_id
 * @property string $participant_id
 * @property int $rank
 * @property int $matches_played
 * @property int $wins
 * @property int $draws
 * @property int $losses
 * @property int $byes
 * @property float $points
 * @property float $buchholz
 * @property float $median_buchholz
 * @property float $progressive
 * @property float $opponent_win_percentage
 * @property array<string, mixed>|null $accumulated_stats
 * @property array<string, mixed>|null $calculated_tiebreakers
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read TournamentModel $tournament
 * @property-read ParticipantModel $participant
 */
final class StandingModel extends Model
{
    use HasUuids;

    protected $table = 'tournaments_standings';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tournament_id',
        'participant_id',
        'rank',
        'matches_played',
        'wins',
        'draws',
        'losses',
        'byes',
        'points',
        'buchholz',
        'median_buchholz',
        'progressive',
        'opponent_win_percentage',
        'accumulated_stats',
        'calculated_tiebreakers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'matches_played' => 'integer',
            'wins' => 'integer',
            'draws' => 'integer',
            'losses' => 'integer',
            'byes' => 'integer',
            'points' => 'float',
            'buchholz' => 'float',
            'median_buchholz' => 'float',
            'progressive' => 'float',
            'opponent_win_percentage' => 'float',
            'accumulated_stats' => 'array',
            'calculated_tiebreakers' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TournamentModel, self>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(TournamentModel::class, 'tournament_id');
    }

    /**
     * @return BelongsTo<ParticipantModel, self>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(ParticipantModel::class, 'participant_id');
    }
}
