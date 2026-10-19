<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * A row lock (SELECT … FOR UPDATE) on the tournament, held until the transaction ends (PostgreSQL).
 * SQLite ignores FOR UPDATE: it runs one write transaction at a time, but a transaction only takes
 * the write lock at its first write, so the reads before it are not serialised. That is enough for
 * the test suite, not a guarantee; the guarantee is PostgreSQL's row lock.
 */
final readonly class DatabaseRegistrationLock implements RegistrationLockInterface
{
    public function forTournament(string $tournamentId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($tournamentId, $callback): mixed {
            TournamentModel::query()->whereKey($tournamentId)->lockForUpdate()->value('id');

            return $callback();
        });
    }

    public function forParticipant(string $participantId, Closure $callback): mixed
    {
        return $this->forTournamentOf(ParticipantModel::query()->whereKey($participantId)->value('tournament_id'), $callback);
    }

    public function forConfirmationToken(string $token, Closure $callback): mixed
    {
        return $this->forTournamentOf(ParticipantModel::query()->where('confirmation_token', $token)->value('tournament_id'), $callback);
    }

    /**
     * Without a participant there is nothing to lock: the callback reports it as not found.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function forTournamentOf(mixed $tournamentId, Closure $callback): mixed
    {
        if (! is_string($tournamentId)) {
            return DB::transaction($callback);
        }

        return $this->forTournament($tournamentId, $callback);
    }
}
