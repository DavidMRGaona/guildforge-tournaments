<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

final readonly class RegisterParticipantDTO
{
    public function __construct(
        public string $tournamentId,
        public ?string $userId = null,
        public ?string $guestName = null,
        public ?string $guestEmail = null,
        public ?int $seed = null,
    ) {
    }

    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tournamentId: $data['tournament_id'],
            userId: $data['user_id'] ?? null,
            guestName: $data['guest_name'] ?? null,
            guestEmail: $data['guest_email'] ?? null,
            seed: $data['seed'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tournament_id' => $this->tournamentId,
            'user_id' => $this->userId,
            'guest_name' => $this->guestName,
            'guest_email' => $this->guestEmail,
            'seed' => $this->seed,
        ];
    }
}
