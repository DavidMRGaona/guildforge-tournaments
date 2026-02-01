<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;

final class GameProfile
{
    /**
     * @param  array<StatDefinition>  $statDefinitions
     * @param  array<ScoringRule>  $scoringRules
     * @param  array<TiebreakerDefinition>  $tiebreakerConfig
     */
    public function __construct(
        private readonly GameProfileId $id,
        private string $name,
        private string $slug,
        private ?string $description,
        private array $statDefinitions,
        private array $scoringRules,
        private array $tiebreakerConfig,
        private PairingConfig $pairingConfig,
        private bool $isSystem = false,
        private readonly ?DateTimeImmutable $createdAt = null,
        private readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function id(): GameProfileId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<StatDefinition>
     */
    public function statDefinitions(): array
    {
        return $this->statDefinitions;
    }

    /**
     * @return array<ScoringRule>
     */
    public function scoringRules(): array
    {
        return $this->scoringRules;
    }

    /**
     * @return array<TiebreakerDefinition>
     */
    public function tiebreakerConfig(): array
    {
        return $this->tiebreakerConfig;
    }

    public function pairingConfig(): PairingConfig
    {
        return $this->pairingConfig;
    }

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Update the profile name.
     */
    public function updateName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Update the profile slug.
     */
    public function updateSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    /**
     * Update the profile description.
     */
    public function updateDescription(?string $description): void
    {
        $this->description = $description;
    }

    /**
     * Update the stat definitions.
     *
     * @param  array<StatDefinition>  $statDefinitions
     */
    public function updateStatDefinitions(array $statDefinitions): void
    {
        $this->statDefinitions = $statDefinitions;
    }

    /**
     * Update the scoring rules.
     *
     * @param  array<ScoringRule>  $scoringRules
     */
    public function updateScoringRules(array $scoringRules): void
    {
        $this->scoringRules = $scoringRules;
    }

    /**
     * Update the tiebreaker configuration.
     *
     * @param  array<TiebreakerDefinition>  $tiebreakerConfig
     */
    public function updateTiebreakerConfig(array $tiebreakerConfig): void
    {
        $this->tiebreakerConfig = $tiebreakerConfig;
    }

    /**
     * Update the pairing configuration.
     */
    public function updatePairingConfig(PairingConfig $pairingConfig): void
    {
        $this->pairingConfig = $pairingConfig;
    }

    /**
     * Get a stat definition by key.
     */
    public function getStatDefinition(string $key): ?StatDefinition
    {
        foreach ($this->statDefinitions as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Check if a stat key exists in this profile.
     */
    public function hasStatDefinition(string $key): bool
    {
        return $this->getStatDefinition($key) !== null;
    }

    /**
     * Get tiebreaker definition by key.
     */
    public function getTiebreakerDefinition(string $key): ?TiebreakerDefinition
    {
        foreach ($this->tiebreakerConfig as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Check if this profile can be deleted.
     * System profiles cannot be deleted.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->isSystem;
    }

    /**
     * Check if this profile can be modified.
     * System profiles can only have their description modified.
     */
    public function canBeModified(): bool
    {
        return ! $this->isSystem;
    }
}
