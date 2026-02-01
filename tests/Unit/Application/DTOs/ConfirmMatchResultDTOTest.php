<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Application\DTOs;

use Modules\Tournaments\Application\DTOs\ConfirmMatchResultDTO;
use PHPUnit\Framework\TestCase;

final class ConfirmMatchResultDTOTest extends TestCase
{
    public function test_can_create_dto(): void
    {
        $dto = new ConfirmMatchResultDTO(
            matchId: 'match-123',
            confirmedById: 'user-456',
        );

        $this->assertEquals('match-123', $dto->matchId);
        $this->assertEquals('user-456', $dto->confirmedById);
    }

    public function test_from_array_creates_dto(): void
    {
        $data = [
            'match_id' => 'match-789',
            'confirmed_by_id' => 'user-abc',
        ];

        $dto = ConfirmMatchResultDTO::fromArray($data);

        $this->assertEquals('match-789', $dto->matchId);
        $this->assertEquals('user-abc', $dto->confirmedById);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        $dto = new ConfirmMatchResultDTO(
            matchId: 'match-xyz',
            confirmedById: 'user-xyz',
        );

        $array = $dto->toArray();

        $this->assertEquals('match-xyz', $array['match_id']);
        $this->assertEquals('user-xyz', $array['confirmed_by_id']);
    }
}
