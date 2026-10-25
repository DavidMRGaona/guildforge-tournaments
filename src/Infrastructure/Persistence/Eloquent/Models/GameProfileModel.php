<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasSlug;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;

/**
 * @phpstan-import-type StatDefinitionData from StatDefinition
 * @phpstan-import-type ScoringRuleData from ScoringRule
 * @phpstan-import-type TiebreakerDefinitionData from TiebreakerDefinition
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array<int, StatDefinitionData> $stat_definitions
 * @property array<int, ScoringRuleData> $scoring_rules
 * @property array<int, TiebreakerDefinitionData> $tiebreaker_config
 * @property array<string, mixed> $pairing_config
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, TournamentModel> $tournaments
 */
final class GameProfileModel extends Model
{
    use HasSlug;
    use HasUuids;

    protected $table = 'tournaments_game_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'slug',
        'description',
        'stat_definitions',
        'scoring_rules',
        'tiebreaker_config',
        'pairing_config',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stat_definitions' => 'array',
            'scoring_rules' => 'array',
            'tiebreaker_config' => 'array',
            'pairing_config' => 'array',
            'is_system' => 'boolean',
        ];
    }

    public function getSlugEntityType(): string
    {
        return 'game_profile';
    }

    protected function getSlugSourceField(): string
    {
        return 'name';
    }

    /**
     * @return HasMany<TournamentModel, $this>
     */
    public function tournaments(): HasMany
    {
        return $this->hasMany(TournamentModel::class, 'game_profile_id');
    }
}
