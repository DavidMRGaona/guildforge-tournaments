<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * @property string $id
 * @property string $match_id
 * @property MatchResult|null $previous_result
 * @property MatchResult $new_result
 * @property int|null $previous_player_1_score
 * @property int|null $new_player_1_score
 * @property int|null $previous_player_2_score
 * @property int|null $new_player_2_score
 * @property string $changed_by_id
 * @property string|null $reason
 * @property \Carbon\Carbon $changed_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read MatchModel $match
 * @property-read UserModel $changedBy
 */
final class MatchHistoryModel extends Model
{
    use HasUuids;

    protected $table = 'tournaments_match_history';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'match_id',
        'previous_result',
        'new_result',
        'previous_player_1_score',
        'new_player_1_score',
        'previous_player_2_score',
        'new_player_2_score',
        'changed_by_id',
        'reason',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_result' => MatchResult::class,
            'new_result' => MatchResult::class,
            'previous_player_1_score' => 'integer',
            'new_player_1_score' => 'integer',
            'previous_player_2_score' => 'integer',
            'new_player_2_score' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MatchModel, self>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchModel::class, 'match_id');
    }

    /**
     * @return BelongsTo<UserModel, self>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'changed_by_id');
    }
}
