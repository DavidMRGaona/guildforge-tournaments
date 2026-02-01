<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use PHPUnit\Framework\TestCase;

final class TournamentTest extends TestCase
{
    private function createTournament(TournamentStatus $status = TournamentStatus::Draft): Tournament
    {
        return new Tournament(
            id: TournamentId::generate(),
            eventId: '550e8400-e29b-41d4-a716-446655440000',
            name: 'Test Tournament',
            slug: 'test-tournament',
            status: $status,
            scoreWeights: $this->getDefaultScoreWeights(),
            tiebreakers: [Tiebreaker::Buchholz, Tiebreaker::Progressive],
            resultReporting: ResultReporting::AdminOnly,
        );
    }

    /**
     * @return array<ScoreWeight>
     */
    private function getDefaultScoreWeights(): array
    {
        return [
            new ScoreWeight('Victoria', 'win', 3.0),
            new ScoreWeight('Empate', 'draw', 1.0),
            new ScoreWeight('Derrota', 'loss', 0.0),
            new ScoreWeight('Bye', 'bye', 3.0),
        ];
    }

    public function test_it_creates_tournament_with_required_fields(): void
    {
        $tournament = $this->createTournament();

        $this->assertInstanceOf(TournamentId::class, $tournament->id());
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $tournament->eventId());
        $this->assertEquals('Test Tournament', $tournament->name());
        $this->assertEquals('test-tournament', $tournament->slug());
        $this->assertEquals(TournamentStatus::Draft, $tournament->status());
    }

    public function test_it_has_default_values(): void
    {
        $tournament = $this->createTournament();

        $this->assertNull($tournament->maxRounds());
        $this->assertEquals(0, $tournament->currentRound());
        $this->assertNull($tournament->maxParticipants());
        $this->assertEquals(2, $tournament->minParticipants());
        $this->assertFalse($tournament->allowGuests());
        $this->assertEquals([], $tournament->allowedRoles());
        $this->assertFalse($tournament->requiresCheckIn());
    }

    public function test_draft_can_open_registration(): void
    {
        $tournament = $this->createTournament(TournamentStatus::Draft);

        $tournament->openRegistration();

        $this->assertEquals(TournamentStatus::RegistrationOpen, $tournament->status());
    }

    public function test_open_registration_can_close(): void
    {
        $tournament = $this->createTournament(TournamentStatus::RegistrationOpen);

        $tournament->closeRegistration();

        $this->assertEquals(TournamentStatus::RegistrationClosed, $tournament->status());
    }

    public function test_closed_registration_can_start(): void
    {
        $tournament = $this->createTournament(TournamentStatus::RegistrationClosed);

        $tournament->start();

        $this->assertEquals(TournamentStatus::InProgress, $tournament->status());
    }

    public function test_in_progress_can_finish(): void
    {
        $tournament = $this->createTournament(TournamentStatus::InProgress);

        $tournament->finish();

        $this->assertEquals(TournamentStatus::Finished, $tournament->status());
    }

    public function test_draft_can_cancel(): void
    {
        $tournament = $this->createTournament(TournamentStatus::Draft);

        $tournament->cancel();

        $this->assertEquals(TournamentStatus::Cancelled, $tournament->status());
    }

    public function test_registration_open_can_cancel(): void
    {
        $tournament = $this->createTournament(TournamentStatus::RegistrationOpen);

        $tournament->cancel();

        $this->assertEquals(TournamentStatus::Cancelled, $tournament->status());
    }

    public function test_in_progress_can_cancel(): void
    {
        $tournament = $this->createTournament(TournamentStatus::InProgress);

        $tournament->cancel();

        $this->assertEquals(TournamentStatus::Cancelled, $tournament->status());
    }

    public function test_draft_cannot_start_directly(): void
    {
        $tournament = $this->createTournament(TournamentStatus::Draft);

        $this->expectException(InvalidStateTransitionException::class);

        $tournament->start();
    }

    public function test_finished_cannot_transition(): void
    {
        $tournament = $this->createTournament(TournamentStatus::Finished);

        $this->expectException(InvalidStateTransitionException::class);

        $tournament->cancel();
    }

    public function test_cancelled_cannot_transition(): void
    {
        $tournament = $this->createTournament(TournamentStatus::Cancelled);

        $this->expectException(InvalidStateTransitionException::class);

        $tournament->openRegistration();
    }

    public function test_calculate_recommended_rounds_for_8_players(): void
    {
        $rounds = Tournament::calculateRecommendedRounds(8);

        $this->assertEquals(3, $rounds);
    }

    public function test_calculate_recommended_rounds_for_16_players(): void
    {
        $rounds = Tournament::calculateRecommendedRounds(16);

        $this->assertEquals(4, $rounds);
    }

    public function test_calculate_recommended_rounds_for_32_players(): void
    {
        $rounds = Tournament::calculateRecommendedRounds(32);

        $this->assertEquals(5, $rounds);
    }

    public function test_calculate_recommended_rounds_for_odd_number(): void
    {
        // 10 players => ceil(log2(10)) = ceil(3.32) = 4
        $rounds = Tournament::calculateRecommendedRounds(10);

        $this->assertEquals(4, $rounds);
    }

    public function test_calculate_recommended_rounds_minimum_is_1(): void
    {
        $rounds = Tournament::calculateRecommendedRounds(1);

        $this->assertEquals(1, $rounds);
    }

    public function test_is_registration_open(): void
    {
        $openTournament = $this->createTournament(TournamentStatus::RegistrationOpen);
        $closedTournament = $this->createTournament(TournamentStatus::RegistrationClosed);

        $this->assertTrue($openTournament->isRegistrationOpen());
        $this->assertFalse($closedTournament->isRegistrationOpen());
    }

    public function test_has_max_rounds_when_configured(): void
    {
        $tournament = new Tournament(
            id: TournamentId::generate(),
            eventId: '550e8400-e29b-41d4-a716-446655440000',
            name: 'Test Tournament',
            slug: 'test-tournament',
            status: TournamentStatus::Draft,
            maxRounds: 5,
            scoreWeights: $this->getDefaultScoreWeights(),
            tiebreakers: [Tiebreaker::Buchholz],
            resultReporting: ResultReporting::AdminOnly,
        );

        $this->assertTrue($tournament->hasMaxRounds());
        $this->assertEquals(5, $tournament->maxRounds());
    }

    public function test_increment_current_round(): void
    {
        $tournament = $this->createTournament(TournamentStatus::InProgress);

        $tournament->incrementCurrentRound();

        $this->assertEquals(1, $tournament->currentRound());

        $tournament->incrementCurrentRound();

        $this->assertEquals(2, $tournament->currentRound());
    }

    public function test_get_score_for_result_key(): void
    {
        $tournament = $this->createTournament();

        $this->assertEquals(3.0, $tournament->getScoreForKey('win'));
        $this->assertEquals(1.0, $tournament->getScoreForKey('draw'));
        $this->assertEquals(0.0, $tournament->getScoreForKey('loss'));
        $this->assertEquals(3.0, $tournament->getScoreForKey('bye'));
        $this->assertEquals(0.0, $tournament->getScoreForKey('unknown')); // Default 0 for unknown
    }

    public function test_can_register_user_checks_roles(): void
    {
        $tournamentWithRoles = new Tournament(
            id: TournamentId::generate(),
            eventId: '550e8400-e29b-41d4-a716-446655440000',
            name: 'Test Tournament',
            slug: 'test-tournament',
            status: TournamentStatus::RegistrationOpen,
            scoreWeights: $this->getDefaultScoreWeights(),
            tiebreakers: [Tiebreaker::Buchholz],
            resultReporting: ResultReporting::AdminOnly,
            allowedRoles: ['member', 'editor'],
        );

        // User with allowed role
        $this->assertTrue($tournamentWithRoles->userHasAllowedRole(['member']));
        $this->assertTrue($tournamentWithRoles->userHasAllowedRole(['editor']));
        $this->assertTrue($tournamentWithRoles->userHasAllowedRole(['member', 'admin']));

        // User without allowed role
        $this->assertFalse($tournamentWithRoles->userHasAllowedRole(['admin']));
        $this->assertFalse($tournamentWithRoles->userHasAllowedRole([]));
    }

    public function test_empty_allowed_roles_allows_all_users(): void
    {
        $tournament = $this->createTournament(TournamentStatus::RegistrationOpen);

        // Empty roles means all users are allowed
        $this->assertTrue($tournament->userHasAllowedRole(['any_role']));
        $this->assertTrue($tournament->userHasAllowedRole([]));
    }

    public function test_allows_guests_when_configured(): void
    {
        $tournamentWithGuests = new Tournament(
            id: TournamentId::generate(),
            eventId: '550e8400-e29b-41d4-a716-446655440000',
            name: 'Test Tournament',
            slug: 'test-tournament',
            status: TournamentStatus::RegistrationOpen,
            scoreWeights: $this->getDefaultScoreWeights(),
            tiebreakers: [Tiebreaker::Buchholz],
            resultReporting: ResultReporting::AdminOnly,
            allowGuests: true,
        );

        $this->assertTrue($tournamentWithGuests->allowGuests());
    }
}
