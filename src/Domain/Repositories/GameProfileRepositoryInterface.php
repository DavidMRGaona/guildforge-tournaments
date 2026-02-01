<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\GameProfile;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;

interface GameProfileRepositoryInterface
{
    /**
     * Find a game profile by ID.
     */
    public function find(GameProfileId $id): ?GameProfile;

    /**
     * Find a game profile by ID or throw an exception.
     */
    public function findOrFail(GameProfileId $id): GameProfile;

    /**
     * Find a game profile by slug.
     */
    public function findBySlug(string $slug): ?GameProfile;

    /**
     * Find all system profiles.
     *
     * @return array<GameProfile>
     */
    public function findSystemProfiles(): array;

    /**
     * Find all game profiles.
     *
     * @return array<GameProfile>
     */
    public function findAll(): array;

    /**
     * Save a game profile (create or update).
     */
    public function save(GameProfile $profile): void;

    /**
     * Delete a game profile.
     */
    public function delete(GameProfileId $id): void;
}
