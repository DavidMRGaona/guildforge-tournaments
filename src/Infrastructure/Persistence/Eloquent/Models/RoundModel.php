<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tournaments\Domain\Enums\RoundStatus;

/**
 * @property string $id
 * @property string $tournament_id
 * @property int $round_number
 * @property RoundStatus $status
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read TournamentModel $tournament
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MatchModel> $matches
 */
final class RoundModel extends Model
{
    use HasUuids;

    protected $table = 'tournaments_rounds';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tournament_id',
        'round_number',
        'status',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'status' => RoundStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<MatchModel, self>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(MatchModel::class, 'round_id');
    }
}
