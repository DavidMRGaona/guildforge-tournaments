<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Notifications;

use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\MatchResultDisputedNotification;
use Modules\Tournaments\Notifications\MatchResultReportedNotification;
use Modules\Tournaments\Notifications\ParticipantConfirmedNotification;
use Modules\Tournaments\Notifications\ParticipantDisqualifiedNotification;
use Modules\Tournaments\Notifications\ParticipantRegisteredToParticipant;
use Modules\Tournaments\Notifications\ParticipantWithdrawnToParticipant;
use Modules\Tournaments\Notifications\TournamentCancelledNotification;
use Modules\Tournaments\Notifications\TournamentReminderNotification;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Modules\Tournaments\TournamentsServiceProvider;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What the bell of the site shows to a participant with an account, and what it never shows:
 * nothing for an address, nothing about a tournament the person can no longer see.
 */
final class SiteNoticesTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    private UserModel $user;

    private ParticipantModel $entry;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');

        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'registration_open',
            'result_reporting' => 'players_with_confirmation',
            'notification_email' => 'organizacion@example.com',
        ]);
        $this->user = UserModel::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
        $this->entry = $this->enter($this->user);
    }

    public function test_the_categories_show_notices_on_the_site(): void
    {
        $registry = app(MailCategoryRegistryInterface::class);

        foreach ([TournamentsServiceProvider::MAIL_REGISTRATIONS, TournamentsServiceProvider::MAIL_RESULTS, TournamentsServiceProvider::MAIL_REMINDERS] as $key) {
            $this->assertTrue($registry->find($key)?->site, $key);
        }
    }

    public function test_every_notification_to_a_participant_reaches_the_bell_with_a_relative_link(): void
    {
        $id = $this->tournament->id;
        $path = route('tournaments.show', $this->tournament->slug, absolute: false);

        $notifications = [
            new ParticipantRegisteredToParticipant('Torneo de prueba', 'Ana', null, false, null, tournamentSlug: $this->tournament->slug, tournamentId: $id),
            new ParticipantConfirmedNotification('Ana', 'Torneo de prueba', null, false, null, $this->tournament->slug, tournamentId: $id),
            new ParticipantDisqualifiedNotification('Ana', 'Torneo de prueba', 'Juego sucio', $id),
            new ParticipantWithdrawnToParticipant('Ana', 'Torneo de prueba', null, $this->tournament->slug, $id),
            new TournamentCancelledNotification('Ana', 'Torneo de prueba', null, $id),
            new TournamentReminderNotification('Ana', 'Torneo de prueba', $this->tournament->slug, '1 de enero', false, null, tournamentId: $id),
            new MatchResultReportedNotification('Ana', 'Luis', 'Torneo de prueba', $this->tournament->slug, 'Gana Luis', MatchResultReportedNotification::TO_CONFIRM, $id, 'other'),
            new MatchResultDisputedNotification('Ana', 'Luis', 'Torneo de prueba', $this->tournament->slug, 'Gana Ana', 'Fue empate', false, $id, 'other'),
        ];

        foreach ($notifications as $notification) {
            $this->user->notify($notification);
            $stored = app(SiteNotificationServiceInterface::class)->latest($this->user, 1);

            $this->assertCount(1, $stored, $notification::class);
            $this->assertSame($path, $stored[0]->url, $notification::class);
            app(SiteNotificationServiceInterface::class)->markAllRead($this->user);
        }
    }

    public function test_an_address_that_is_not_an_account_gets_nothing_on_the_site_and_no_error(): void
    {
        $address = new AnonymousNotifiable('invitada@example.com', 'Invitada');

        $address->notify(new TournamentCancelledNotification('Invitada', 'Torneo de prueba', null, $this->tournament->id));

        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($this->user));
    }

    public function test_the_copy_of_a_dispute_to_the_organization_is_mail_only(): void
    {
        $notification = new MatchResultDisputedNotification(null, 'Luis', 'Torneo de prueba', $this->tournament->slug, 'Gana Ana', 'Fue empate', true, $this->tournament->id, 'other');

        $this->assertNull($notification->toSiteNotification($this->user));
    }

    public function test_a_draft_tournament_shows_nothing(): void
    {
        $this->tournament->update(['status' => 'draft']);

        $this->user->notify(new TournamentCancelledNotification('Ana', 'Torneo de prueba', null, $this->tournament->id));

        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($this->user));
    }

    public function test_a_person_who_is_no_longer_in_the_tournament_gets_nothing(): void
    {
        $this->entry->delete();

        $this->user->notify(new ParticipantConfirmedNotification('Ana', 'Torneo de prueba', null, false, null, $this->tournament->slug, tournamentId: $this->tournament->id));

        $this->assertSame(0, app(SiteNotificationServiceInterface::class)->total($this->user));
    }

    public function test_a_notification_queued_before_the_tournament_id_existed_shows_nothing_on_the_site(): void
    {
        $notification = new TournamentCancelledNotification('Ana', 'Torneo de prueba', null);

        $this->assertNull($notification->toSiteNotification($this->user));
        $this->assertNull($notification->mailSubject());
    }

    public function test_the_reminder_goes_to_the_check_in_when_it_is_open_and_to_the_tournament_otherwise(): void
    {
        $this->tournament->update(['requires_check_in' => true, 'self_check_in_allowed' => true, 'check_in_starts_before' => 60]);
        $this->eventOf()->update(['start_date' => now()->addDays(3)]);
        $reminder = fn (): TournamentReminderNotification => new TournamentReminderNotification('Ana', 'Torneo de prueba', $this->tournament->slug, '1 de enero', true, 60, selfCheckInAllowed: true, tournamentId: $this->tournament->id);

        $this->assertSame(route('tournaments.show', $this->tournament->slug, absolute: false), $this->urlOf($reminder()));

        $this->eventOf()->update(['start_date' => now()->addMinutes(30)]);

        $this->assertSame(route('tournaments.check-in.show', $this->tournament->slug, absolute: false), $this->urlOf($reminder()));
    }

    public function test_the_mail_subject_is_the_tournament(): void
    {
        $subject = (new TournamentCancelledNotification('Ana', 'Torneo de prueba', null, $this->tournament->id))->mailSubject();

        $this->assertSame('tournaments.tournament', $subject?->type);
        $this->assertSame($this->tournament->id, $subject->id);
        $this->assertSame('Torneo de prueba', $subject->label);
    }

    public function test_a_reported_result_notifies_the_rival_and_groups_by_tournament_under_the_reporter(): void
    {
        $reporter = UserModel::factory()->create(['name' => 'Luis', 'email' => 'luis@example.com']);
        $reporterEntry = $this->enter($reporter);
        $this->tournament->update(['status' => 'in_progress']);
        $round = RoundModel::create(['tournament_id' => $this->tournament->id, 'round_number' => 1, 'status' => 'in_progress']);

        foreach ([1, 2] as $_) {
            $match = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $reporterEntry->id, 'player_2_id' => $this->entry->id, 'result' => 'not_played']);
            app(MatchReportingServiceInterface::class)->report(new ReportMatchResultDTO(
                matchId: $match->id,
                result: MatchResult::PlayerOneWin,
                reportedById: $reporter->id,
            ));
        }

        $service = app(SiteNotificationServiceInterface::class);
        $items = $service->latest($this->user, 10);

        $this->assertCount(1, $items, 'both notices share the tournament group');
        $this->assertSame(1, $service->unreadCount($this->user));
        $this->assertSame(0, $service->total($reporter));
    }

    public function test_the_dispute_reaches_the_reporter_on_the_bell(): void
    {
        $rival = UserModel::factory()->create(['name' => 'Luis', 'email' => 'luis@example.com']);
        $rivalEntry = $this->enter($rival);
        $this->tournament->update(['status' => 'in_progress']);
        $round = RoundModel::create(['tournament_id' => $this->tournament->id, 'round_number' => 1, 'status' => 'in_progress']);
        $match = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $this->entry->id, 'player_2_id' => $rivalEntry->id, 'result' => 'not_played']);
        app(MatchReportingServiceInterface::class)->report(new ReportMatchResultDTO(matchId: $match->id, result: MatchResult::PlayerOneWin, reportedById: $this->user->id));
        $report = app(MatchReportRepositoryInterface::class)->find($match->id);
        $this->assertNotNull($report);
        $service = app(SiteNotificationServiceInterface::class);
        $service->markAllRead($rival);

        app(MatchReportingServiceInterface::class)->dispute($match->id, $rival->id, 'Fue empate', $report->fingerprint());

        $this->assertSame(1, $service->unreadCount($this->user));
        $this->assertStringContainsString('Luis', $service->latest($this->user, 1)[0]->title);
    }

    public function test_deleting_the_tournament_forgets_its_notices(): void
    {
        $service = app(SiteNotificationServiceInterface::class);
        $this->user->notify(new TournamentCancelledNotification('Ana', 'Torneo de prueba', null, $this->tournament->id));
        $this->assertSame(1, $service->total($this->user));

        $this->tournament->delete();

        $this->assertSame(0, $service->total($this->user));
    }

    private function urlOf(TournamentReminderNotification $notification): ?string
    {
        return $notification->toSiteNotification($this->user)?->url;
    }

    private function eventOf(): EventModel
    {
        $event = EventModel::query()->find($this->tournament->event_id);
        $this->assertInstanceOf(EventModel::class, $event);

        return $event;
    }

    private function enter(UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }
}
