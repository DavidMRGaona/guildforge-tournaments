<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs;

final readonly class ConfirmMatchResultDTO
{
    public function __construct(
        public string $matchId,
        public string $confirmedById,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            matchId: $data['match_id'],
            confirmedById: $data['confirmed_by_id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'match_id' => $this->matchId,
            'confirmed_by_id' => $this->confirmedById,
        ];
    }
}
