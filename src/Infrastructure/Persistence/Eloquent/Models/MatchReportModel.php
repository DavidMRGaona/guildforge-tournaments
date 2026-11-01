<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\StoresInstantsInStorageTimezone;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * @property string $id
 * @property string $match_id
 * @property MatchResult $result
 * @property int|null $player_1_score
 * @property int|null $player_2_score
 * @property array<string, mixed>|null $player_1_stats
 * @property array<string, mixed>|null $player_2_stats
 * @property string $reported_by_id
 * @property Carbon $reported_at
 * @property string|null $disputed_by_id
 * @property string|null $dispute_reason
 * @property Carbon|null $disputed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MatchModel $match
 * @property-read UserModel $reportedBy
 */
final class MatchReportModel extends Model
{
    use HasUuids;
    use StoresInstantsInStorageTimezone;

    protected $table = 'tournaments_match_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'match_id',
        'result',
        'player_1_score',
        'player_2_score',
        'player_1_stats',
        'player_2_stats',
        'reported_by_id',
        'reported_at',
        'disputed_by_id',
        'dispute_reason',
        'disputed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => MatchResult::class,
            'player_1_score' => 'integer',
            'player_2_score' => 'integer',
            'player_1_stats' => 'array',
            'player_2_stats' => 'array',
            'reported_at' => 'datetime',
            'disputed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MatchModel, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchModel::class, 'match_id');
    }

    /**
     * @return BelongsTo<UserModel, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class, 'reported_by_id');
    }
}
