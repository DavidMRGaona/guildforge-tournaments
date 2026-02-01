<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use PHPUnit\Framework\TestCase;

final class MatchIdTest extends TestCase
{
    public function test_it_generates_valid_uuid(): void
    {
        $id = MatchId::generate();

        $this->assertNotEmpty($id->value);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $id->value
        );
    }

    public function test_it_creates_from_string(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = MatchId::fromString($uuid);

        $this->assertEquals($uuid, $id->value);
    }

    public function test_it_throws_for_invalid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MatchId::fromString('invalid-uuid');
    }

    public function test_it_converts_to_string(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = MatchId::fromString($uuid);

        $this->assertEquals($uuid, (string) $id);
    }

    public function test_it_compares_equality(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = MatchId::fromString($uuid);
        $id2 = MatchId::fromString($uuid);
        $id3 = MatchId::generate();

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }
}
