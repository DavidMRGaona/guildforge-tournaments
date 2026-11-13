<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Notifications;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationItemDTO;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Domain\Events\ParticipantWithdrawn;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Notifications\ParticipantRegisteredToOrganizer;
use Modules\Tournaments\Notifications\TournamentManagementNotification;
use Modules\Tournaments\TournamentsServiceProvider;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What the bell shows to the accounts that manage tournaments, on top of the mail to the
 * tournament's address: registrations and withdrawals to whoever manages participants, disputed
 * results to whoever records them, each checked again when delivered.
 */
final class ManagerNoticesTest extends ModuleTestCase
{
    private const string PARTICIPANTS = 'tournaments:tournaments.manage_participants';

    private const string RESULTS = 'tournaments:tournaments.report_results';

    private const string UPDATE = 'tournaments:tournaments.update';

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        // The panel is built before the test case enables the module: its resource has no routes yet
        $this->registerOnAdminPanel([TournamentResource::class]);

        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'registration_open',
            'result_reporting' => 'players_with_confirmation',
            'notification_email' => 'organizacion@example.com',
        ]);
    }

    public function test_the_management_category_is_optional_bell_only_and_shown_to_managers(): void
    {
        $category = app(MailCategoryRegistryInterface::class)->find(TournamentsServiceProvider::MAIL_MANAGEMENT);

        $this->assertNotNull($category);
        $this->assertFalse($category->isMandatory());
        $this->assertTrue($category->site);
        $this->assertFalse($category->mail);
        $this->assertNotNull($category->audience);
        $this->assertTrue(($category->audience)($this->userWith(self::PARTICIPANTS)));
        $this->assertTrue(($category->audience)($this->userWith(self::RESULTS)));
        $this->assertFalse(($category->audience)($this->userWith(self::UPDATE)));
    }

    public function test_a_registration_reaches_whoever_manages_participants_and_links_to_their_tab_in_the_panel(): void
    {
        $manager = $this->userWith(self::PARTICIPANTS, self::UPDATE, 'admin.access');
        $referee = $this->userWith(self::RESULTS, self::UPDATE, 'admin.access');
        $stranger = UserModel::factory()->create();

        $this->register(UserModel::factory()->create(['name' => 'Ana']));

        $notices = $this->bell()->latest($manager, 10);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('Ana', $notices[0]->title);
        $this->assertSame(TournamentResource::getUrl('edit', ['record' => $this->tournament->id, 'relation' => '0'], isAbsolute: false), $notices[0]->url);
        $this->assertSame(0, $this->bell()->total($referee));
        $this->assertSame(0, $this->bell()->total($stranger));
    }

    public function test_the_mail_to_the_tournament_address_is_still_sent(): void
    {
        $sent = [];
        Event::listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
            $sent[] = $event->notification::class.'@'.$event->channel;
        });
        $this->userWith(self::PARTICIPANTS);

        $this->register(UserModel::factory()->create());

        $this->assertContains(ParticipantRegisteredToOrganizer::class.'@mail', $sent);
        $this->assertContains(TournamentManagementNotification::class.'@'.HasSiteNotification::CHANNEL, $sent);
        $this->assertNotContains(TournamentManagementNotification::class.'@mail', $sent);
    }

    public function test_a_manager_who_cannot_open_the_tournament_in_the_panel_is_sent_to_its_public_page(): void
    {
        $manager = $this->userWith(self::PARTICIPANTS);

        $this->register(UserModel::factory()->create());

        $this->assertSame(route('tournaments.show', $this->tournament->slug, absolute: false), $this->bell()->latest($manager, 1)[0]->url);
    }

    public function test_a_draft_tournament_reaches_only_whoever_can_open_it_in_the_panel(): void
    {
        $this->tournament->update(['status' => 'draft']);
        $withPanel = $this->userWith(self::PARTICIPANTS, self::UPDATE, 'admin.access');
        $withoutPanel = $this->userWith(self::PARTICIPANTS);

        $this->register(UserModel::factory()->create());

        $this->assertSame(1, $this->bell()->total($withPanel));
        $this->assertSame(0, $this->bell()->total($withoutPanel));
    }

    public function test_a_manager_is_not_told_about_their_own_registration(): void
    {
        $manager = $this->userWith(self::PARTICIPANTS);

        $this->register($manager);

        $categories = array_map(static fn (SiteNotificationItemDTO $notice): string => $notice->category, $this->bell()->latest($manager, 10));
        $this->assertSame([TournamentsServiceProvider::MAIL_REGISTRATIONS], $categories, 'only their own notice as a participant');
    }

    public function test_a_registration_added_from_the_panel_is_not_news_to_the_managers(): void
    {
        $manager = $this->userWith(self::PARTICIPANTS);

        $this->register(UserModel::factory()->create(), notifyOrganizer: false);

        $this->assertSame(0, $this->bell()->total($manager));
    }

    public function test_registrations_and_withdrawals_of_a_tournament_share_one_group_apart_from_the_player_notices(): void
    {
        $manager = $this->userWith(self::PARTICIPANTS);
        $this->enter($manager);
        $leaving = UserModel::factory()->create(['name' => 'Luis']);

        $this->register(UserModel::factory()->create());
        $entry = $this->register($leaving);
        event(ParticipantWithdrawn::create($entry->id, $this->tournament->id, $leaving->id, $leaving->email, 'Luis'));

        $notices = $this->bell()->latest($manager, 10);
        $this->assertCount(1, $notices);
        $this->assertSame(3, $notices[0]->count);
        $this->assertStringContainsString('3', $notices[0]->title);
        $this->assertStringContainsString('Torneo de prueba', $notices[0]->title);
    }

    public function test_a_disputed_result_reaches_whoever_records_results_and_links_to_the_matches_tab(): void
    {
        $referee = $this->userWith(self::RESULTS, self::UPDATE, 'admin.access');
        $manager = $this->userWith(self::PARTICIPANTS, self::UPDATE, 'admin.access');

        $this->disputeAMatch();

        $notices = $this->bell()->latest($referee, 10);
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('Luis', $notices[0]->title);
        $this->assertSame(TournamentResource::getUrl('edit', ['record' => $this->tournament->id, 'relation' => '2'], isAbsolute: false), $notices[0]->url);
        $this->assertSame(0, $this->bell()->total($manager));
    }

    public function test_an_account_that_lost_the_permission_before_delivery_gets_nothing(): void
    {
        $manager = $this->userWith(self::PARTICIPANTS);
        $notification = new TournamentManagementNotification(
            kind: TournamentManagementNotification::REGISTERED,
            tournamentId: $this->tournament->id,
            tournamentName: 'Torneo de prueba',
            actorName: 'Ana',
            actorId: null,
        );

        $manager->roles()->detach();
        app(AuthorizationServiceInterface::class)->invalidateCacheFor($manager);
        $manager->notify($notification);

        $this->assertSame(0, $this->bell()->total($manager));
    }

    public function test_deleting_the_tournament_forgets_the_manager_notices(): void
    {
        $manager = $this->userWith(self::PARTICIPANTS);
        $this->register(UserModel::factory()->create());
        $this->assertSame(1, $this->bell()->total($manager));

        $this->tournament->delete();

        $this->assertSame(0, $this->bell()->total($manager));
    }

    private function register(UserModel $user, bool $notifyOrganizer = true): ParticipantModel
    {
        $entry = $this->enter($user);
        event(ParticipantRegistered::create($entry->id, $this->tournament->id, $user->id, null, false, notifyOrganizer: $notifyOrganizer));

        return $entry;
    }

    private function disputeAMatch(): void
    {
        $reporter = UserModel::factory()->create(['name' => 'Ana']);
        $rival = UserModel::factory()->create(['name' => 'Luis']);
        $one = $this->enter($reporter);
        $two = $this->enter($rival);
        $this->tournament->update(['status' => 'in_progress']);
        $round = RoundModel::create(['tournament_id' => $this->tournament->id, 'round_number' => 1, 'status' => 'in_progress']);
        $match = MatchModel::create(['round_id' => $round->id, 'player_1_id' => $one->id, 'player_2_id' => $two->id, 'result' => 'not_played']);
        app(MatchReportingServiceInterface::class)->report(new ReportMatchResultDTO(matchId: $match->id, result: MatchResult::PlayerOneWin, reportedById: $reporter->id));
        $report = app(MatchReportRepositoryInterface::class)->find($match->id);
        $this->assertNotNull($report);

        app(MatchReportingServiceInterface::class)->dispute($match->id, $rival->id, 'Fue empate', $report->fingerprint());
    }

    private function enter(UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }

    private function userWith(string ...$keys): UserModel
    {
        $role = RoleModel::create(['name' => 'role-'.Str::lower(Str::random(6)), 'display_name' => 'Rol de ejemplo']);

        foreach ($keys as $key) {
            // "module:resource.action" for a module permission, "resource.action" for a core one
            $parts = explode(':', $key, 2);
            $module = isset($parts[1]) ? $parts[0] : null;
            [$resource, $action] = explode('.', $parts[1] ?? $parts[0], 2);
            $permission = PermissionModel::firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => $resource, 'action' => $action, 'module' => $module],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function bell(): SiteNotificationServiceInterface
    {
        return app(SiteNotificationServiceInterface::class);
    }
}
