<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Notifications;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The opponent learns of a reported result, and the organization of a disputed one.
 * AnonymousNotifiable has no key, so Notification::fake() cannot record it: read the array mailer.
 */
final class MatchResultEmailsTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    private RoundModel $round;

    private UserModel $reporter;

    private ParticipantModel $reporterEntry;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');

        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
            'notification_email' => 'organizacion@example.com',
        ]);
        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        $this->reporter = UserModel::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $this->reporterEntry = $this->entry(['user_id' => $this->reporter->id]);
    }

    public function test_rival_with_account_gets_confirm_link_in_confirmation_mode(): void
    {
        $rival = UserModel::factory()->create(['name' => 'Luis', 'email' => 'luis@example.com']);
        $match = $this->match($this->entry(['user_id' => $rival->id]));

        $this->report($match, 20, 15);

        $mails = $this->mails();
        $this->assertSame(['luis@example.com'], array_column($mails, 'to'));
        $this->assertStringContainsString('Ana', $mails[0]['text']);
        $this->assertStringContainsString('Gana Ana (20-15)', $mails[0]['text']);
        $this->assertStringContainsString('confirmarlo o discutirlo', $mails[0]['text']);
        $this->assertStringContainsString(route('tournaments.show', $this->tournament->slug), $mails[0]['text']);
    }

    public function test_trusted_mode_mail_tells_rival_to_talk_to_the_organizer(): void
    {
        $this->tournament->update(['result_reporting' => 'players_trusted']);
        $rival = UserModel::factory()->create(['name' => 'Luis', 'email' => 'luis@example.com']);
        $match = $this->match($this->entry(['user_id' => $rival->id]));

        $this->report($match);

        $mails = $this->mails();
        $this->assertSame(['luis@example.com'], array_column($mails, 'to'));
        $this->assertStringContainsString('ya cuenta', $mails[0]['text']);
        $this->assertStringContainsString('organización', $mails[0]['text']);
        $this->assertStringNotContainsString('discutirlo', $mails[0]['text']);
    }

    public function test_guest_rival_with_confirmed_email_is_told_the_organizer_confirms(): void
    {
        $match = $this->match($this->entry([
            'guest_name' => 'Invitada',
            'guest_email' => 'invitada@example.com',
            'email_confirmed_at' => now(),
        ]));

        $this->report($match);

        $mails = $this->mails();
        $this->assertSame(['invitada@example.com'], array_column($mails, 'to'));
        $this->assertStringContainsString('La organización lo confirmará', $mails[0]['text']);
    }

    public function test_unconfirmed_guest_or_anonymised_rival_gets_nothing(): void
    {
        $guest = $this->match($this->entry(['guest_name' => 'Invitada', 'guest_email' => 'invitada@example.com']));
        $anonymised = UserModel::factory()->create(['email' => 'anon@example.com', 'anonymized_at' => now()]);
        $gone = $this->match($this->entry(['user_id' => $anonymised->id]));

        $this->report($guest);
        $this->report($gone);

        $this->assertSame([], $this->mails());
    }

    public function test_dispute_mails_organizer_and_reporter_with_escaped_reason(): void
    {
        $rival = UserModel::factory()->create(['name' => 'Luis', 'email' => 'luis@example.com']);
        $match = $this->match($this->entry(['user_id' => $rival->id]));
        $this->report($match);
        $this->clearMails();
        $report = app(MatchReportRepositoryInterface::class)->find($match->id);
        $this->assertInstanceOf(MatchReport::class, $report);

        app(MatchReportingServiceInterface::class)->dispute($match->id, $rival->id, 'Fue *empate* [ver](http://evil.example)', $report->fingerprint());

        $mails = $this->mails();
        $this->assertEqualsCanonicalizing(['organizacion@example.com', 'ana@example.com'], array_column($mails, 'to'));
        foreach ($mails as $mail) {
            $this->assertStringContainsString('Luis', $mail['text']);
            $this->assertStringContainsString('Fue \*empate\* \[ver\]', $mail['text']);
        }
    }

    private function report(MatchModel $match, ?int $score1 = null, ?int $score2 = null): void
    {
        app(MatchReportingServiceInterface::class)->report(new ReportMatchResultDTO(
            matchId: $match->id,
            result: MatchResult::PlayerOneWin,
            reportedById: $this->reporter->id,
            player1Score: $score1,
            player2Score: $score2,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'status' => 'confirmed',
            ...$attributes,
        ]);
    }

    private function match(ParticipantModel $rival): MatchModel
    {
        return MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->reporterEntry->id,
            'player_2_id' => $rival->id,
            'result' => 'not_played',
        ]);
    }

    /**
     * @return list<array{to: string, text: string}>
     */
    private function mails(): array
    {
        return array_values(array_map(function (SentMessage $message): array {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);

            return ['to' => $email->getTo()[0]->getAddress(), 'text' => (string) $email->getTextBody()];
        }, $this->transport()->messages()->all()));
    }

    private function clearMails(): void
    {
        $this->transport()->flush();
    }

    private function transport(): ArrayTransport
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }
}
