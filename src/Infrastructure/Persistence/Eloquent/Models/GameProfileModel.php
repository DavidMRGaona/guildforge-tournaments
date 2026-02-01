<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array<string, mixed> $stat_definitions
 * @property array<string, mixed> $scoring_rules
 * @property array<string, mixed> $tiebreaker_config
 * @property array<string, mixed> $pairing_config
 * @property bool $is_system
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TournamentModel> $tournaments
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
     * @return HasMany<TournamentModel, self>
     */
    public function tournaments(): HasMany
    {
        return $this->hasMany(TournamentModel::class, 'game_profile_id');
    }
}
