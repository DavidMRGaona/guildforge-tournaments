<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Services\SwissPairingServiceInterface;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Infrastructure\Services\SwissPairingService;
use PHPUnit\Framework\TestCase;

final class SwissPairingServiceTest extends TestCase
{
    private SwissPairingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SwissPairingService();
    }

    public function test_it_implements_swiss_pairing_service_interface(): void
    {
        $this->assertInstanceOf(SwissPairingServiceInterface::class, $this->service);
    }

    public function test_first_round_pairs_all_participants(): void
    {
        $participants = $this->createParticipants(4);
        $standings = [];
        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 1
        );

        // Should create 2 matches for 4 participants
        $this->assertCount(2, $matches);

        // All participants should be assigned
        $assignedParticipants = [];
        foreach ($matches as $match) {
            $assignedParticipants[] = $match->player1Id();
            if ($match->player2Id() !== null) {
                $assignedParticipants[] = $match->player2Id();
            }
        }
        $this->assertCount(4, $assignedParticipants);

        // All should be unique
        $this->assertCount(4, array_unique($assignedParticipants));
    }

    public function test_pairs_by_similar_score(): void
    {
        $participants = $this->createParticipants(4);
        $tournamentId = 'tournament-id';

        // Create standings with clear score differences
        // P1: 6 points (leader), P2: 6 points (leader)
        // P3: 0 points (loser), P4: 0 points (loser)
        $standings = [
            $this->createStanding($participants[0]->id()->value, $tournamentId, rank: 1, points: 6.0),
            $this->createStanding($participants[1]->id()->value, $tournamentId, rank: 2, points: 6.0),
            $this->createStanding($participants[2]->id()->value, $tournamentId, rank: 3, points: 0.0),
            $this->createStanding($participants[3]->id()->value, $tournamentId, rank: 4, points: 0.0),
        ];

        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 2
        );

        $this->assertCount(2, $matches);

        // Extract match pairings
        $pairings = [];
        foreach ($matches as $match) {
            $pairings[] = [
                'p1' => $match->player1Id(),
                'p2' => $match->player2Id(),
            ];
        }

        // Leaders should be paired together (P1 vs P2)
        // Losers should be paired together (P3 vs P4)
        $leaderPairing = $this->findPairingWith($pairings, $participants[0]->id()->value);
        $this->assertTrue(
            $leaderPairing['p2'] === $participants[1]->id()->value,
            'Leaders should be paired together'
        );

        $loserPairing = $this->findPairingWith($pairings, $participants[2]->id()->value);
        $this->assertTrue(
            $loserPairing['p2'] === $participants[3]->id()->value,
            'Losers should be paired together'
        );
    }

    public function test_avoids_rematches(): void
    {
        $participants = $this->createParticipants(4);
        $tournamentId = 'tournament-id';

        // All have same points so only previous matchups matter
        $standings = [
            $this->createStanding($participants[0]->id()->value, $tournamentId, rank: 1, points: 3.0),
            $this->createStanding($participants[1]->id()->value, $tournamentId, rank: 2, points: 3.0),
            $this->createStanding($participants[2]->id()->value, $tournamentId, rank: 3, points: 3.0),
            $this->createStanding($participants[3]->id()->value, $tournamentId, rank: 4, points: 3.0),
        ];

        // Previous matchups: P1 vs P2, P3 vs P4
        $previousMatchups = [
            ['player1Id' => $participants[0]->id()->value, 'player2Id' => $participants[1]->id()->value],
            ['player1Id' => $participants[2]->id()->value, 'player2Id' => $participants[3]->id()->value],
        ];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 2
        );

        $this->assertCount(2, $matches);

        // Verify no rematches occurred
        foreach ($matches as $match) {
            $p1 = $match->player1Id();
            $p2 = $match->player2Id();

            if ($p2 === null) {
                continue; // Skip bye matches
            }

            // Check it's not a rematch
            $isRematch = false;
            foreach ($previousMatchups as $prev) {
                if (
                    ($prev['player1Id'] === $p1 && $prev['player2Id'] === $p2) ||
                    ($prev['player1Id'] === $p2 && $prev['player2Id'] === $p1)
                ) {
                    $isRematch = true;
                    break;
                }
            }

            $this->assertFalse($isRematch, "Rematch detected between {$p1} and {$p2}");
        }
    }

    public function test_assigns_bye_to_lowest_ranked_without_previous_bye(): void
    {
        // Odd number of participants requires a bye
        $participants = $this->createParticipants(5);
        $tournamentId = 'tournament-id';

        // P5 is lowest ranked
        $standings = [
            $this->createStanding($participants[0]->id()->value, $tournamentId, rank: 1, points: 9.0),
            $this->createStanding($participants[1]->id()->value, $tournamentId, rank: 2, points: 6.0),
            $this->createStanding($participants[2]->id()->value, $tournamentId, rank: 3, points: 3.0),
            $this->createStanding($participants[3]->id()->value, $tournamentId, rank: 4, points: 1.0),
            $this->createStanding($participants[4]->id()->value, $tournamentId, rank: 5, points: 0.0),
        ];

        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 2
        );

        // Should create 2 regular matches and 1 bye match
        $this->assertCount(3, $matches);

        // Find the bye match
        $byeMatch = null;
        foreach ($matches as $match) {
            if ($match->isBye()) {
                $byeMatch = $match;
                break;
            }
        }

        $this->assertNotNull($byeMatch, 'A bye match should be generated');
        $this->assertEquals(
            $participants[4]->id()->value,
            $byeMatch->player1Id(),
            'Lowest ranked player should receive the bye'
        );
        $this->assertEquals(MatchResult::Bye, $byeMatch->result());
    }

    public function test_bye_goes_to_second_lowest_when_lowest_already_had_bye(): void
    {
        // 5 participants, P5 already had a bye
        $participants = $this->createParticipantsWithByeStatus(
            count: 5,
            byeReceivedIndexes: [4] // P5 already had bye
        );
        $tournamentId = 'tournament-id';

        $standings = [
            $this->createStanding($participants[0]->id()->value, $tournamentId, rank: 1, points: 6.0),
            $this->createStanding($participants[1]->id()->value, $tournamentId, rank: 2, points: 6.0),
            $this->createStanding($participants[2]->id()->value, $tournamentId, rank: 3, points: 3.0),
            $this->createStanding($participants[3]->id()->value, $tournamentId, rank: 4, points: 0.0),
            $this->createStanding($participants[4]->id()->value, $tournamentId, rank: 5, points: 0.0), // Already had bye
        ];

        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 3
        );

        $byeMatch = null;
        foreach ($matches as $match) {
            if ($match->isBye()) {
                $byeMatch = $match;
                break;
            }
        }

        $this->assertNotNull($byeMatch);
        // P4 should get the bye since P5 already had one
        $this->assertEquals(
            $participants[3]->id()->value,
            $byeMatch->player1Id(),
            'Second lowest ranked player (who hasnt had bye) should receive the bye'
        );
    }

    public function test_handles_odd_number_of_players(): void
    {
        $participants = $this->createParticipants(7);
        $standings = [];
        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 1
        );

        // 7 players: 3 regular matches + 1 bye match = 4 total
        $this->assertCount(4, $matches);

        // Count bye matches
        $byeCount = 0;
        $regularCount = 0;
        foreach ($matches as $match) {
            if ($match->isBye()) {
                $byeCount++;
            } else {
                $regularCount++;
            }
        }

        $this->assertEquals(1, $byeCount, 'Should have exactly one bye match');
        $this->assertEquals(3, $regularCount, 'Should have 3 regular matches');

        // Verify all participants are assigned
        $assignedParticipants = [];
        foreach ($matches as $match) {
            $assignedParticipants[] = $match->player1Id();
            if ($match->player2Id() !== null) {
                $assignedParticipants[] = $match->player2Id();
            }
        }
        $this->assertCount(7, $assignedParticipants);
        $this->assertCount(7, array_unique($assignedParticipants));
    }

    public function test_assigns_table_numbers(): void
    {
        $participants = $this->createParticipants(4);
        $standings = [];
        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 1
        );

        $tableNumbers = [];
        foreach ($matches as $match) {
            if (! $match->isBye()) {
                $this->assertNotNull($match->tableNumber(), 'Regular matches should have table numbers');
                $tableNumbers[] = $match->tableNumber();
            } else {
                $this->assertNull($match->tableNumber(), 'Bye matches should not have table numbers');
            }
        }

        // Table numbers should be unique and sequential starting from 1
        $this->assertCount(2, $tableNumbers);
        sort($tableNumbers);
        $this->assertEquals([1, 2], $tableNumbers);
    }

    public function test_all_matches_have_not_played_result_initially(): void
    {
        $participants = $this->createParticipants(4);
        $standings = [];
        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 1
        );

        foreach ($matches as $match) {
            if ($match->isBye()) {
                $this->assertEquals(MatchResult::Bye, $match->result());
            } else {
                $this->assertEquals(MatchResult::NotPlayed, $match->result());
            }
        }
    }

    public function test_handles_two_participants(): void
    {
        $participants = $this->createParticipants(2);
        $standings = [];
        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 1
        );

        $this->assertCount(1, $matches);
        $this->assertFalse($matches[0]->isBye());
        $this->assertNotNull($matches[0]->player1Id());
        $this->assertNotNull($matches[0]->player2Id());
    }

    public function test_handles_single_participant(): void
    {
        $participants = $this->createParticipants(1);
        $standings = [];
        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 1
        );

        // Single participant gets a bye
        $this->assertCount(1, $matches);
        $this->assertTrue($matches[0]->isBye());
    }

    public function test_returns_empty_array_for_no_participants(): void
    {
        $matches = $this->service->generatePairings(
            participants: [],
            standings: [],
            previousMatchups: [],
            roundNumber: 1
        );

        $this->assertCount(0, $matches);
    }

    public function test_handles_large_tournament(): void
    {
        $participants = $this->createParticipants(32);
        $standings = [];
        $previousMatchups = [];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 1
        );

        // 32 players = 16 matches
        $this->assertCount(16, $matches);

        // All should be regular matches (no byes)
        foreach ($matches as $match) {
            $this->assertFalse($match->isBye());
        }
    }

    public function test_complex_scenario_avoids_rematches_while_respecting_score(): void
    {
        // 6 participants after round 1:
        // P1 beat P2, P3 beat P4, P5 beat P6
        $participants = $this->createParticipants(6);
        $tournamentId = 'tournament-id';

        // Winners: P1, P3, P5 (3 pts each)
        // Losers: P2, P4, P6 (0 pts each)
        $standings = [
            $this->createStanding($participants[0]->id()->value, $tournamentId, rank: 1, points: 3.0),
            $this->createStanding($participants[2]->id()->value, $tournamentId, rank: 2, points: 3.0),
            $this->createStanding($participants[4]->id()->value, $tournamentId, rank: 3, points: 3.0),
            $this->createStanding($participants[1]->id()->value, $tournamentId, rank: 4, points: 0.0),
            $this->createStanding($participants[3]->id()->value, $tournamentId, rank: 5, points: 0.0),
            $this->createStanding($participants[5]->id()->value, $tournamentId, rank: 6, points: 0.0),
        ];

        $previousMatchups = [
            ['player1Id' => $participants[0]->id()->value, 'player2Id' => $participants[1]->id()->value],
            ['player1Id' => $participants[2]->id()->value, 'player2Id' => $participants[3]->id()->value],
            ['player1Id' => $participants[4]->id()->value, 'player2Id' => $participants[5]->id()->value],
        ];

        $matches = $this->service->generatePairings(
            $participants,
            $standings,
            $previousMatchups,
            roundNumber: 2
        );

        $this->assertCount(3, $matches);

        // Verify no rematches and reasonable pairings
        foreach ($matches as $match) {
            $p1 = $match->player1Id();
            $p2 = $match->player2Id();

            foreach ($previousMatchups as $prev) {
                $this->assertFalse(
                    ($prev['player1Id'] === $p1 && $prev['player2Id'] === $p2) ||
                    ($prev['player1Id'] === $p2 && $prev['player2Id'] === $p1),
                    'No rematches should occur'
                );
            }
        }
    }

    /**
     * @return array<Participant>
     */
    private function createParticipants(int $count): array
    {
        $participants = [];
        $tournamentId = 'tournament-id';

        for ($i = 0; $i < $count; $i++) {
            $participants[] = new Participant(
                id: ParticipantId::generate(),
                tournamentId: $tournamentId,
                status: ParticipantStatus::CheckedIn,
                userId: 'user-' . ($i + 1),
            );
        }

        return $participants;
    }

    /**
     * @param  array<int>  $byeReceivedIndexes
     * @return array<Participant>
     */
    private function createParticipantsWithByeStatus(int $count, array $byeReceivedIndexes = []): array
    {
        $participants = [];
        $tournamentId = 'tournament-id';

        for ($i = 0; $i < $count; $i++) {
            $participant = new Participant(
                id: ParticipantId::generate(),
                tournamentId: $tournamentId,
                status: ParticipantStatus::CheckedIn,
                userId: 'user-' . ($i + 1),
                hasReceivedBye: in_array($i, $byeReceivedIndexes, true),
            );
            $participants[] = $participant;
        }

        return $participants;
    }

    private function createStanding(
        string $participantId,
        string $tournamentId,
        int $rank,
        float $points
    ): Standing {
        return new Standing(
            id: 'standing-' . $participantId,
            tournamentId: $tournamentId,
            participantId: $participantId,
            rank: $rank,
            matchesPlayed: (int) ($points / 3),
            wins: (int) ($points / 3),
            draws: 0,
            losses: 0,
            byes: 0,
            points: $points,
        );
    }

    /**
     * @param  array<array{p1: string, p2: ?string}>  $pairings
     * @return array{p1: string, p2: ?string}|null
     */
    private function findPairingWith(array $pairings, string $participantId): ?array
    {
        foreach ($pairings as $pairing) {
            if ($pairing['p1'] === $participantId || $pairing['p2'] === $participantId) {
                return $pairing;
            }
        }

        return null;
    }
}
