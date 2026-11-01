<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Domain\Services;

use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\Tiebreaker;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\MatchReportClosedException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Exceptions\UnauthorizedToReportResultException;
use Modules\Tournaments\Domain\Services\MatchReportRules;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use Modules\Tournaments\Domain\ValueObjects\ScoreWeight;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MatchReportRulesTest extends TestCase
{
    private const string PLAYER_ONE = '11111111-1111-4111-8111-111111111111';

    private const string PLAYER_TWO = '22222222-2222-4222-8222-222222222222';

    private const string STRANGER = '33333333-3333-4333-8333-333333333333';

    private const string USER_ONE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string USER_TWO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private MatchReportRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new MatchReportRules;
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array{bool, bool, bool, bool}}>
     */
    public static function cases(): iterable
    {
        // [overrides], [canReport, canCorrect, canConfirm, canDispute]
        yield 'player one, nothing reported' => [[], [true, false, false, false]];
        yield 'player two, nothing reported' => [['actor' => 'two'], [true, false, false, false]];
        yield 'trusted mode, nothing reported' => [['mode' => ResultReporting::PlayersTrusted], [true, false, false, false]];
        yield 'admin only' => [['mode' => ResultReporting::AdminOnly], [false, false, false, false]];
        yield 'round pending' => [['round' => RoundStatus::Pending], [false, false, false, false]];
        yield 'round finished' => [['round' => RoundStatus::Finished], [false, false, false, false]];
        yield 'tournament finished' => [['tournament' => TournamentStatus::Finished], [false, false, false, false]];
        yield 'bye' => [['bye' => true], [false, false, false, false]];
        yield 'official result' => [['official' => true], [false, false, false, false]];
        yield 'stranger' => [['actor' => 'stranger'], [false, false, false, false]];
        yield 'no participant' => [['actor' => 'none'], [false, false, false, false]];
        yield 'withdrawn actor' => [['status' => ParticipantStatus::Withdrawn], [false, false, false, false]];
        yield 'disqualified actor' => [['status' => ParticipantStatus::Disqualified], [false, false, false, false]];
        yield 'registered, not confirmed' => [['status' => ParticipantStatus::Registered], [false, false, false, false]];
        yield 'checked in' => [['status' => ParticipantStatus::CheckedIn], [true, false, false, false]];
        yield 'own report' => [['report' => 'own'], [false, true, false, false]];
        yield 'own disputed report' => [['report' => 'own-disputed'], [false, true, false, false]];
        yield 'opponent report' => [['report' => 'opponent'], [false, false, true, true]];
        yield 'opponent disputed report' => [['report' => 'opponent-disputed'], [false, false, false, false]];
        yield 'opponent report, trusted mode' => [['report' => 'opponent', 'mode' => ResultReporting::PlayersTrusted], [false, false, false, false]];
        yield 'opponent report, round finished' => [['report' => 'opponent', 'round' => RoundStatus::Finished], [false, false, false, false]];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array{bool, bool, bool, bool}  $expected
     */
    #[DataProvider('cases')]
    public function test_actions_follow_the_rules(array $overrides, array $expected): void
    {
        [$tournament, $round, $match, $report, $actor] = $this->scenario($overrides);

        $actions = $this->rules->actionsFor($tournament, $round, $match, $report, $actor);

        $this->assertSame(
            $expected,
            [$actions->canReport, $actions->canCorrect, $actions->canConfirm, $actions->canDispute],
        );
    }

    public function test_admin_only_mode_refuses_with_its_reason(): void
    {
        [$tournament, $round, $match, $report, $actor] = $this->scenario(['mode' => ResultReporting::AdminOnly]);

        $this->expectException(UnauthorizedToReportResultException::class);
        $this->expectExceptionMessage('Only administrators');

        $this->rules->assertCanReport($tournament, $round, $match, $report, $actor);
    }

    public function test_a_finished_round_closes_reporting(): void
    {
        [$tournament, $round, $match, $report, $actor] = $this->scenario(['round' => RoundStatus::Finished]);

        $this->expectException(MatchReportClosedException::class);

        $this->rules->assertCanReport($tournament, $round, $match, $report, $actor);
    }

    public function test_a_report_of_the_opponent_cannot_be_overwritten(): void
    {
        [$tournament, $round, $match, $report, $actor] = $this->scenario(['report' => 'opponent']);

        $this->expectException(MatchReportClosedException::class);

        $this->rules->assertCanReport($tournament, $round, $match, $report, $actor);
    }

    public function test_reporter_cannot_confirm_own_report(): void
    {
        [$tournament, $round, $match, $report, $actor] = $this->scenario(['report' => 'own']);

        $this->expectException(UnauthorizedToReportResultException::class);

        $this->rules->assertCanConfirm($tournament, $round, $match, $report, $actor);
    }

    public function test_nothing_to_confirm_without_a_report(): void
    {
        [$tournament, $round, $match, $report, $actor] = $this->scenario([]);

        $this->expectException(ResultNotConfirmedException::class);

        $this->rules->assertCanConfirm($tournament, $round, $match, $report, $actor);
    }

    public function test_a_disputed_report_cannot_be_disputed_again(): void
    {
        [$tournament, $round, $match, $report, $actor] = $this->scenario(['report' => 'opponent-disputed']);

        $this->expectException(ResultNotConfirmedException::class);

        $this->rules->assertCanDispute($tournament, $round, $match, $report, $actor);
    }

    /**
     * @param  array<string, mixed>  $o
     * @return array{Tournament, Round, TournamentMatch, MatchReport|null, Participant|null}
     */
    private function scenario(array $o): array
    {
        $mode = $o['mode'] ?? ResultReporting::PlayersWithConfirmation;
        $this->assertInstanceOf(ResultReporting::class, $mode);
        $tournamentStatus = $o['tournament'] ?? TournamentStatus::InProgress;
        $this->assertInstanceOf(TournamentStatus::class, $tournamentStatus);
        $roundStatus = $o['round'] ?? RoundStatus::InProgress;
        $this->assertInstanceOf(RoundStatus::class, $roundStatus);
        $status = $o['status'] ?? ParticipantStatus::Confirmed;
        $this->assertInstanceOf(ParticipantStatus::class, $status);

        $tournament = new Tournament(
            id: TournamentId::generate(),
            eventId: '550e8400-e29b-41d4-a716-446655440000',
            name: 'Test Tournament',
            slug: 'test-tournament',
            status: $tournamentStatus,
            scoreWeights: [new ScoreWeight('Victoria', 'win', 3.0)],
            tiebreakers: [Tiebreaker::Buchholz],
            resultReporting: $mode,
        );
        $round = new Round(RoundId::generate(), $tournament->id()->value, 1, $roundStatus);

        $match = new TournamentMatch(
            id: MatchId::generate(),
            roundId: $round->id()->value,
            player1Id: self::PLAYER_ONE,
            player2Id: ($o['bye'] ?? false) === true ? null : self::PLAYER_TWO,
            result: ($o['official'] ?? false) === true ? MatchResult::Draw : MatchResult::NotPlayed,
        );

        $actor = match ($o['actor'] ?? 'one') {
            'one' => new Participant(ParticipantId::fromString(self::PLAYER_ONE), $tournament->id()->value, $status, self::USER_ONE),
            'two' => new Participant(ParticipantId::fromString(self::PLAYER_TWO), $tournament->id()->value, $status, self::USER_TWO),
            'stranger' => new Participant(ParticipantId::fromString(self::STRANGER), $tournament->id()->value, $status, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'),
            default => null,
        };

        $reporter = match ($o['report'] ?? null) {
            'own', 'own-disputed' => self::USER_ONE,
            'opponent', 'opponent-disputed' => self::USER_TWO,
            default => null,
        };
        $report = null;
        if ($reporter !== null) {
            $report = new MatchReport($match->id()->value, MatchResult::PlayerOneWin, $reporter, new DateTimeImmutable('2030-05-01 10:00:00'));
            if (str_ends_with((string) $o['report'], 'disputed')) {
                $report->dispute($reporter === self::USER_ONE ? self::USER_TWO : self::USER_ONE, 'No', new DateTimeImmutable('2030-05-01 10:05:00'));
            }
        }

        return [$tournament, $round, $match, $report, $actor];
    }
}
