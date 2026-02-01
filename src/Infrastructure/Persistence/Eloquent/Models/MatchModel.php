<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * @property string $id
 * @property string $round_id
 * @property string $player_1_id
 * @property string|null $player_2_id
 * @property int|null $table_number
 * @property MatchResult $result
 * @property int|null $player_1_score
 * @property int|null $player_2_score
 * @property string|null $reported_by_id
 * @property \Carbon\Carbon|null $reported_at
 * @property string|null $confirmed_by_id
 * @property \Carbon\Carbon|null $confirmed_at
 * @property bool $is_disputed
 * @property array<string, mixed>|null $player_1_stats
 * @property array<string, mixed>|null $player_2_stats
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read RoundModel $round
 * @property-read ParticipantModel $player1
 * @property-read ParticipantModel|null $player2
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MatchHistoryModel> $history
 */
final class MatchModel extends Model
{
    use HasUuids;

    protected $table = 'tournaments_matches';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'round_id',
        'player_1_id',
        'player_2_id',
        'table_number',
        'result',
        'player_1_score',
        'player_2_score',
        'reported_by_id',
        'reported_at',
        'confirmed_by_id',
        'confirmed_at',
        'is_disputed',
        'player_1_stats',
        'player_2_stats',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => MatchResult::class,
            'table_number' => 'integer',
            'player_1_score' => 'integer',
            'player_2_score' => 'integer',
            'is_disputed' => 'boolean',
            'reported_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'player_1_stats' => 'array',
            'player_2_stats' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RoundModel, self>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(RoundModel::class, 'round_id');
    }

    /**
     * @return BelongsTo<ParticipantModel, self>
     */
    public function player1(): BelongsTo
    {
        return $this->belongsTo(ParticipantModel::class, 'player_1_id');
    }

    /**
     * @return BelongsTo<ParticipantModel, self>
     */
    public function player2(): BelongsTo
    {
        return $this->belongsTo(ParticipantModel::class, 'player_2_id');
    }

    /**
     * @return HasMany<MatchHistoryModel, self>
     */
    public function history(): HasMany
    {
        return $this->hasMany(MatchHistoryModel::class, 'match_id');
    }
}
