<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Tournaments\Application\Services\TournamentOrganizersInterface;

final readonly class TournamentOrganizers implements TournamentOrganizersInterface
{
    public function userIds(string $tournamentId): array
    {
        return array_values($this->organizers($tournamentId)
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    public function isOrganizer(string $tournamentId, string $userId): bool
    {
        return $this->organizers($tournamentId)->where('users.id', $userId)->exists();
    }

    /**
     * @return Builder<UserModel>
     */
    private function organizers(string $tournamentId): Builder
    {
        return UserModel::query()
            ->join('tournaments_organizers', 'tournaments_organizers.user_id', '=', 'users.id')
            ->where('tournaments_organizers.tournament_id', $tournamentId)
            ->whereNull('users.anonymized_at');
    }
}
