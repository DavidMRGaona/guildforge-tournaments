<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use PHPUnit\Framework\TestCase;

final class TournamentIdTest extends TestCase
{
    public function test_it_generates_valid_uuid(): void
    {
        $id = TournamentId::generate();

        $this->assertNotEmpty($id->value);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id->value
        );
    }

    public function test_it_creates_from_string(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = TournamentId::fromString($uuid);

        $this->assertEquals($uuid, $id->value);
    }

    public function test_it_throws_for_invalid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TournamentId::fromString('invalid-uuid');
    }

    public function test_it_converts_to_string(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = TournamentId::fromString($uuid);

        $this->assertEquals($uuid, (string) $id);
    }

    public function test_it_compares_equality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = TournamentId::fromString($uuid);
        $id2 = TournamentId::fromString($uuid);
        $id3 = TournamentId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }
}
