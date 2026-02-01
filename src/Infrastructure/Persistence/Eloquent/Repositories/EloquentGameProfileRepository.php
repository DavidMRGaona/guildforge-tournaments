<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Tournaments\Domain\Entities\GameProfile;
use Modules\Tournaments\Domain\Exceptions\GameProfileNotFoundException;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;

final readonly class EloquentGameProfileRepository implements GameProfileRepositoryInterface
{
    public function find(GameProfileId $id): ?GameProfile
    {
        $model = GameProfileModel::find($id->value);

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findOrFail(GameProfileId $id): GameProfile
    {
        $profile = $this->find($id);

        if ($profile === null) {
            throw GameProfileNotFoundException::withId($id->value);
        }

        return $profile;
    }

    public function findBySlug(string $slug): ?GameProfile
    {
        $model = GameProfileModel::where('slug', $slug)->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    /**
     * @return array<GameProfile>
     */
    public function findSystemProfiles(): array
    {
        $models = GameProfileModel::where('is_system', true)->get();

        return $models->map(fn (GameProfileModel $model): GameProfile => $this->toEntity($model))->all();
    }

    /**
     * @return array<GameProfile>
     */
    public function findAll(): array
    {
        $models = GameProfileModel::all();

        return $models->map(fn (GameProfileModel $model): GameProfile => $this->toEntity($model))->all();
    }

    public function save(GameProfile $profile): void
    {
        GameProfileModel::updateOrCreate(
            ['id' => $profile->id()->value],
            [
                'name' => $profile->name(),
                'slug' => $profile->slug(),
                'description' => $profile->description(),
                'stat_definitions' => array_map(
                    static fn (StatDefinition $sd): array => $sd->toArray(),
                    $profile->statDefinitions()
                ),
                'scoring_rules' => array_map(
                    static fn (ScoringRule $sr): array => $sr->toArray(),
                    $profile->scoringRules()
                ),
                'tiebreaker_config' => array_map(
                    static fn (TiebreakerDefinition $td): array => $td->toArray(),
                    $profile->tiebreakerConfig()
                ),
                'pairing_config' => $profile->pairingConfig()->toArray(),
                'is_system' => $profile->isSystem(),
            ]
        );
    }

    public function delete(GameProfileId $id): void
    {
        GameProfileModel::where('id', $id->value)->delete();
    }

    private function toEntity(GameProfileModel $model): GameProfile
    {
        $statDefinitionsData = $model->stat_definitions ?? [];
        $statDefinitions = array_map(
            static fn (array $data): StatDefinition => StatDefinition::fromArray($data),
            $statDefinitionsData
        );

        $scoringRulesData = $model->scoring_rules ?? [];
        $scoringRules = array_map(
            static fn (array $data): ScoringRule => ScoringRule::fromArray($data),
            $scoringRulesData
        );

        $tiebreakerConfigData = $model->tiebreaker_config ?? [];
        $tiebreakerConfig = array_map(
            static fn (array $data): TiebreakerDefinition => TiebreakerDefinition::fromArray($data),
            $tiebreakerConfigData
        );

        $pairingConfigData = $model->pairing_config ?? [];
        $pairingConfig = PairingConfig::fromArray($pairingConfigData);

        return new GameProfile(
            id: GameProfileId::fromString($model->id),
            name: $model->name,
            slug: $model->slug,
            description: $model->description,
            statDefinitions: $statDefinitions,
            scoringRules: $scoringRules,
            tiebreakerConfig: $tiebreakerConfig,
            pairingConfig: $pairingConfig,
            isSystem: $model->is_system,
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
        );
    }
}
