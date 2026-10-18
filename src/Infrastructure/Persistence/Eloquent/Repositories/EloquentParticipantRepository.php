<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;

final readonly class EloquentParticipantRepository implements ParticipantRepositoryInterface
{
    private const string ACTIVE_GUEST_EMAIL_INDEX = 'tournaments_participants_active_guest_email_unique';

    /**
     * @throws AlreadyRegisteredException when another active guest already uses the email in the tournament
     */
    public function save(Participant $participant): void
    {
        $guestEmail = $this->normalizeEmail($participant->guestEmail());

        try {
            $this->persist($participant, $guestEmail);
        } catch (UniqueConstraintViolationException $exception) {
            if ($guestEmail !== null && $this->isActiveGuestEmailViolation($exception)) {
                throw AlreadyRegisteredException::guestWithEmailAlreadyRegistered($participant->tournamentId(), $guestEmail);
            }

            throw $exception;
        }
    }

    private function persist(Participant $participant, ?string $guestEmail): void
    {
        ParticipantModel::updateOrCreate(
            ['id' => $participant->id()->value],
            [
                'tournament_id' => $participant->tournamentId(),
                'user_id' => $participant->userId(),
                'guest_name' => $participant->guestName(),
                'guest_email' => $guestEmail,
                'cancellation_token' => $participant->cancellationToken(),
                'status' => $participant->status(),
                'seed' => $participant->seed(),
                'has_received_bye' => $participant->hasReceivedBye(),
                'registered_at' => $participant->registeredAt(),
                'checked_in_at' => $participant->checkedInAt(),
                'email_confirmed_at' => $participant->emailConfirmedAt(),
                'confirmation_expires_at' => $participant->confirmationExpiresAt(),
                'confirmation_token' => $participant->confirmationToken(),
                'confirmation_sent_at' => $participant->confirmationSentAt(),
                'confirmation_mode' => $participant->confirmationMode(),
                'fingerprint' => $participant->fingerprint(),
            ]
        );
    }

    public function find(ParticipantId $id): ?Participant
    {
        $model = ParticipantModel::find($id->value);

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findOrFail(ParticipantId $id): Participant
    {
        $participant = $this->find($id);

        if ($participant === null) {
            throw ParticipantNotFoundException::withId($id->value);
        }

        return $participant;
    }

    /**
     * @return array<Participant>
     */
    public function findByTournament(string $tournamentId): array
    {
        $models = ParticipantModel::where('tournament_id', $tournamentId)->get();

        return $models->map(fn (ParticipantModel $model): Participant => $this->toEntity($model))->all();
    }

    /**
     * @return array<Participant>
     */
    public function findByTournamentAndStatus(string $tournamentId, ParticipantStatus $status): array
    {
        $models = ParticipantModel::where('tournament_id', $tournamentId)
            ->where('status', $status)
            ->get();

        return $models->map(fn (ParticipantModel $model): Participant => $this->toEntity($model))->all();
    }

    public function findByUserAndTournament(string $userId, string $tournamentId): ?Participant
    {
        $model = ParticipantModel::where('tournament_id', $tournamentId)
            ->where('user_id', $userId)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByGuestEmailAndTournament(string $email, string $tournamentId): ?Participant
    {
        $model = $this->guestEmailQuery($email, $tournamentId)->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByEmailAndTournament(string $email, string $tournamentId): ?Participant
    {
        // First check guest_email
        $model = $this->guestEmailQuery($email, $tournamentId)->first();

        if ($model !== null) {
            return $this->toEntity($model);
        }

        // Then check user's email through the user relation
        $normalized = $this->normalizeEmail($email);
        $model = ParticipantModel::where('tournament_id', $tournamentId)
            ->whereHas('user', function ($query) use ($normalized): void {
                $query->whereRaw('lower(email) = ?', [$normalized]);
            })
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function countByTournament(string $tournamentId): int
    {
        return ParticipantModel::where('tournament_id', $tournamentId)->count();
    }

    public function countActiveByTournament(string $tournamentId): int
    {
        $activeStatuses = [
            ParticipantStatus::Registered,
            ParticipantStatus::Confirmed,
            ParticipantStatus::CheckedIn,
        ];

        // A guest in "wait" mode with an unconfirmed email does not take a seat yet
        return ParticipantModel::where('tournament_id', $tournamentId)
            ->whereIn('status', $activeStatuses)
            ->where(function (Builder $query): void {
                $query->whereNull('confirmation_mode')
                    ->orWhere('confirmation_mode', '!=', GuestConfirmationMode::Wait->value)
                    ->orWhereNotNull('email_confirmed_at');
            })
            ->count();
    }

    /**
     * @return array<Participant>
     */
    public function findPlayableByTournament(string $tournamentId): array
    {
        $playableStatuses = [
            ParticipantStatus::Confirmed,
            ParticipantStatus::CheckedIn,
        ];

        $models = ParticipantModel::where('tournament_id', $tournamentId)
            ->whereIn('status', $playableStatuses)
            ->get();

        return $models->map(fn (ParticipantModel $model): Participant => $this->toEntity($model))->all();
    }

    public function delete(ParticipantId $id): void
    {
        ParticipantModel::where('id', $id->value)->delete();
    }

    public function findByCancellationToken(string $token): ?Participant
    {
        $model = ParticipantModel::where('cancellation_token', $token)->first();

        if ($model === null) {
            return null;
        }

        return $this->toEntity($model);
    }

    public function findByConfirmationToken(string $token): ?Participant
    {
        $model = ParticipantModel::where('confirmation_token', $token)->first();

        return $model === null ? null : $this->toEntity($model);
    }

    public function countUnconfirmedHeldByTournament(string $tournamentId): int
    {
        return $this->awaitingConfirmationQuery()
            ->where('tournament_id', $tournamentId)
            ->where('confirmation_mode', GuestConfirmationMode::Hold->value)
            ->count();
    }

    /**
     * @return list<string>
     */
    public function findIdsAwaitingConfirmation(): array
    {
        /** @var list<string> */
        return $this->awaitingConfirmationQuery()->pluck('id')->values()->all();
    }

    /**
     * @return list<string>
     */
    public function findTournamentIdsWithFingerprints(): array
    {
        /** @var list<string> */
        return ParticipantModel::whereNotNull('fingerprint')
            ->distinct()
            ->pluck('tournament_id')
            ->values()
            ->all();
    }

    public function clearFingerprints(string $tournamentId): int
    {
        return ParticipantModel::where('tournament_id', $tournamentId)
            ->whereNotNull('fingerprint')
            ->update(['fingerprint' => null]);
    }

    /**
     * @return list<string>
     */
    public function findPossibleDuplicateIds(string $tournamentId, int $windowHours): array
    {
        $models = ParticipantModel::where('tournament_id', $tournamentId)
            ->whereIn('status', $this->activeStatuses())
            ->whereNotNull('fingerprint')
            ->get();

        $window = $windowHours * 3600;
        $duplicates = [];

        foreach ($models->groupBy('fingerprint') as $group) {
            $rows = $group->values();

            foreach ($rows as $i => $a) {
                for ($j = $i + 1; $j < $rows->count(); $j++) {
                    $b = $rows[$j];

                    if ($a->user_id !== null && $b->user_id !== null) {
                        continue;
                    }

                    // Reactivated rows keep their old created_at: the last confirmation request is the later signal
                    $timeA = ($a->confirmation_sent_at ?? $a->created_at)?->getTimestamp();
                    $timeB = ($b->confirmation_sent_at ?? $b->created_at)?->getTimestamp();

                    if ($timeA === null || $timeB === null || abs($timeA - $timeB) > $window) {
                        continue;
                    }

                    $duplicates[$a->id] = true;
                    $duplicates[$b->id] = true;
                }
            }
        }

        return array_map('strval', array_keys($duplicates));
    }

    /**
     * @return array<ParticipantStatus>
     */
    private function activeStatuses(): array
    {
        return [
            ParticipantStatus::Registered,
            ParticipantStatus::Confirmed,
            ParticipantStatus::CheckedIn,
        ];
    }

    /**
     * @return Builder<ParticipantModel>
     */
    private function awaitingConfirmationQuery(): Builder
    {
        return ParticipantModel::whereIn('status', $this->activeStatuses())
            ->whereNull('user_id')
            ->whereNull('email_confirmed_at');
    }

    /**
     * Rows of the guest email in the tournament, active registrations first.
     *
     * @return Builder<ParticipantModel>
     */
    private function guestEmailQuery(string $email, string $tournamentId): Builder
    {
        return ParticipantModel::where('tournament_id', $tournamentId)
            ->whereRaw('lower(guest_email) = ?', [$this->normalizeEmail($email)])
            ->orderByRaw("CASE WHEN status IN ('registered', 'confirmed', 'checked_in') THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');
    }

    private function normalizeEmail(?string $email): ?string
    {
        return $email === null ? null : mb_strtolower(trim($email));
    }

    /**
     * PostgreSQL and SQLite both name the partial expression index in the message.
     */
    private function isActiveGuestEmailViolation(UniqueConstraintViolationException $exception): bool
    {
        return str_contains($exception->getMessage(), self::ACTIVE_GUEST_EMAIL_INDEX);
    }

    private function toEntity(ParticipantModel $model): Participant
    {
        return new Participant(
            id: ParticipantId::fromString($model->id),
            tournamentId: $model->tournament_id,
            status: $model->status,
            userId: $model->user_id,
            guestName: $model->guest_name,
            guestEmail: $model->guest_email,
            cancellationToken: $model->cancellation_token,
            seed: $model->seed,
            hasReceivedBye: $model->has_received_bye,
            registeredAt: $model->registered_at?->toDateTimeImmutable(),
            checkedInAt: $model->checked_in_at?->toDateTimeImmutable(),
            createdAt: $model->created_at?->toDateTimeImmutable(),
            updatedAt: $model->updated_at?->toDateTimeImmutable(),
            emailConfirmedAt: $model->email_confirmed_at?->toDateTimeImmutable(),
            confirmationExpiresAt: $model->confirmation_expires_at?->toDateTimeImmutable(),
            confirmationToken: $model->confirmation_token,
            confirmationSentAt: $model->confirmation_sent_at?->toDateTimeImmutable(),
            confirmationMode: $model->confirmation_mode,
            fingerprint: $model->fingerprint,
        );
    }
}
