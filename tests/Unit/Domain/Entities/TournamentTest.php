<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Entities;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function tournamentWith(
        TournamentStatus $status = TournamentStatus::RegistrationOpen,
        ?string $opensAt = null,
        ?string $closesAt = null,
        ?string $eventStartsAt = '2026-11-20 10:00:00',
    ): Tournament {
        return new Tournament(
            id: TournamentId::generate(),
            eventId: '550e8400-e29b-41d4-a716-446655440000',
            name: 'Test Tournament',
            slug: 'test-tournament',
            status: $status,
            scoreWeights: $this->getDefaultScoreWeights(),
            tiebreakers: [Tiebreaker::Buchholz, Tiebreaker::Progressive],
            resultReporting: ResultReporting::AdminOnly,
            registrationOpensAt: $opensAt !== null ? new DateTimeImmutable($opensAt) : null,
            registrationClosesAt: $closesAt !== null ? new DateTimeImmutable($closesAt) : null,
            eventStartsAt: $eventStartsAt !== null ? new DateTimeImmutable($eventStartsAt) : null,
        );
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

    public function test_is_registration_open_needs_the_open_status(): void
    {
        $now = new DateTimeImmutable('2026-11-01 10:00:00');

        $this->assertTrue($this->tournamentWith(TournamentStatus::RegistrationOpen)->isRegistrationOpen($now));
        $this->assertFalse($this->tournamentWith(TournamentStatus::RegistrationClosed)->isRegistrationOpen($now));
    }

    /**
     * @return iterable<string, array{TournamentStatus, ?string, ?string, ?string, string, bool}>
     */
    public static function registrationWindow(): iterable
    {
        $start = '2026-11-20 10:00:00';

        // status, opens at, closes at, event starts at, now, open?
        yield 'no dates, before the start' => [TournamentStatus::RegistrationOpen, null, null, $start, '2026-11-20 09:59:59', true];
        yield 'no dates, at the exact start' => [TournamentStatus::RegistrationOpen, null, null, $start, '2026-11-20 10:00:00', false];
        yield 'no dates, after the start' => [TournamentStatus::RegistrationOpen, null, null, $start, '2026-11-20 10:01:00', false];
        yield 'before the opening date' => [TournamentStatus::RegistrationOpen, '2026-11-10 10:00:00', null, $start, '2026-11-10 09:59:59', false];
        yield 'at the exact opening date' => [TournamentStatus::RegistrationOpen, '2026-11-10 10:00:00', null, $start, '2026-11-10 10:00:00', true];
        yield 'before the closing date' => [TournamentStatus::RegistrationOpen, null, '2026-11-15 20:00:00', $start, '2026-11-15 19:59:59', true];
        yield 'at the exact closing date' => [TournamentStatus::RegistrationOpen, null, '2026-11-15 20:00:00', $start, '2026-11-15 20:00:00', false];
        yield 'closing date after the start, at the start' => [TournamentStatus::RegistrationOpen, null, '2026-11-20 12:00:00', $start, '2026-11-20 10:00:00', false];
        yield 'inside both dates' => [TournamentStatus::RegistrationOpen, '2026-11-10 10:00:00', '2026-11-15 20:00:00', $start, '2026-11-12 10:00:00', true];
        yield 'unknown start' => [TournamentStatus::RegistrationOpen, null, null, null, '2026-11-01 10:00:00', false];
        yield 'registration closed status' => [TournamentStatus::RegistrationClosed, null, null, $start, '2026-11-01 10:00:00', false];
        yield 'draft status' => [TournamentStatus::Draft, null, null, $start, '2026-11-01 10:00:00', false];
    }

    #[DataProvider('registrationWindow')]
    public function test_registration_is_open_only_inside_its_window(
        TournamentStatus $status,
        ?string $opensAt,
        ?string $closesAt,
        ?string $eventStartsAt,
        string $now,
        bool $expected,
    ): void {
        $tournament = $this->tournamentWith($status, $opensAt, $closesAt, $eventStartsAt);

        $this->assertSame($expected, $tournament->isRegistrationOpen(new DateTimeImmutable($now)));
    }

    public function test_the_check_in_opens_the_configured_minutes_before_the_start(): void
    {
        $start = new DateTimeImmutable('2026-11-20 10:00:00', new DateTimeZone('Europe/Madrid'));

        $this->assertSame('2026-11-20T09:15:00+01:00', Tournament::checkInOpensAt($start, 45)->format('c'));
        $this->assertSame('2026-11-20T09:30:00+01:00', Tournament::checkInOpensAt($start, null)->format('c'));
        $this->assertSame('2026-11-20T10:00:00+01:00', Tournament::checkInOpensAt($start, -10)->format('c'));
    }

    public function test_the_check_in_opening_counts_elapsed_minutes_across_a_clock_change(): void
    {
        $madrid = new DateTimeZone('Europe/Madrid');

        // Clocks go forward at 02:00 CET: 03:10 CEST minus 30 minutes is 01:40 CET, not 03:40
        $this->assertSame(
            '2027-03-28T01:40:00+01:00',
            Tournament::checkInOpensAt(new DateTimeImmutable('2027-03-28 03:10:00', $madrid), 30)->format('c'),
        );

        // Clocks go back at 03:00 CEST: 03:10 CET minus 90 minutes is the first 02:40 (CEST)
        $this->assertSame(
            '2026-10-25T02:40:00+02:00',
            Tournament::checkInOpensAt(new DateTimeImmutable('2026-10-25 03:10:00', $madrid), 90)->format('c'),
        );
    }

    public function test_has_event_started(): void
    {
        $tournament = $this->tournamentWith();

        $this->assertFalse($tournament->hasEventStarted(new DateTimeImmutable('2026-11-20 09:59:59')));
        $this->assertTrue($tournament->hasEventStarted(new DateTimeImmutable('2026-11-20 10:00:00')));
        $this->assertTrue($this->tournamentWith(eventStartsAt: null)->hasEventStarted(new DateTimeImmutable('2026-11-01 10:00:00')));
        $this->assertEquals(new DateTimeImmutable('2026-11-20 10:00:00'), $tournament->eventStartsAt());
    }

    /**
     * @return iterable<string, array{TournamentStatus, ?string, string, bool}>
     */
    public static function withdrawalWindow(): iterable
    {
        $start = '2026-11-20 10:00:00';

        // status, event starts at, now, can withdraw?
        yield 'registration open, before the start' => [TournamentStatus::RegistrationOpen, $start, '2026-11-20 09:59:59', true];
        yield 'registration closed, before the start' => [TournamentStatus::RegistrationClosed, $start, '2026-11-20 09:59:59', true];
        yield 'draft, before the start' => [TournamentStatus::Draft, $start, '2026-11-01 10:00:00', true];
        yield 'registration open, at the exact start' => [TournamentStatus::RegistrationOpen, $start, '2026-11-20 10:00:00', false];
        yield 'registration closed, after the start' => [TournamentStatus::RegistrationClosed, $start, '2026-11-20 11:00:00', false];
        yield 'in progress, before the start' => [TournamentStatus::InProgress, $start, '2026-11-01 10:00:00', false];
        yield 'finished, before the start' => [TournamentStatus::Finished, $start, '2026-11-01 10:00:00', false];
        yield 'cancelled, before the start' => [TournamentStatus::Cancelled, $start, '2026-11-01 10:00:00', false];
        yield 'unknown start' => [TournamentStatus::RegistrationOpen, null, '2026-11-01 10:00:00', false];
    }

    #[DataProvider('withdrawalWindow')]
    public function test_participants_can_withdraw_only_before_the_start(
        TournamentStatus $status,
        ?string $eventStartsAt,
        string $now,
        bool $expected,
    ): void {
        $tournament = $this->tournamentWith($status, eventStartsAt: $eventStartsAt);

        $this->assertSame($expected, $tournament->canWithdraw(new DateTimeImmutable($now)));
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
