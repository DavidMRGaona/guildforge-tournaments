<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\GuestConfirmationRequested;
use Modules\Tournaments\Domain\Events\ParticipantRegistered;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The participants manager adds guests through the service, shows which guests have not
 * confirmed their email (and lets the organisation vouch for it) and flags possible duplicates.
 */
final class ParticipantsPanelGuestTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Madrid');
        $this->travelTo($this->now);
        config()->set('modules.settings.tournaments', []);
        Event::fake([GuestConfirmationRequested::class, ParticipantRegistered::class]);

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    /**
     * Reloaded so the columns left to their database defaults are set, as on a real request.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['start_date' => $this->now->addDays(3)])->id,
            'name' => 'Torneo de prueba',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ])->refresh();
    }

    /**
     * A guest registration from the web form, awaiting confirmation.
     */
    private function pendingGuest(TournamentModel $tournament, string $email, string $fingerprint = ''): ParticipantModel
    {
        $result = app(ParticipantManagementServiceInterface::class)->registerGuest(new RegisterParticipantDTO(
            tournamentId: $tournament->id,
            guestName: 'Invitado '.Str::random(4),
            guestEmail: $email,
            fingerprint: $fingerprint !== '' ? $fingerprint : hash('sha256', $email),
        ));

        return ParticipantModel::query()->findOrFail($result->participantId);
    }

    private function confirmedGuest(TournamentModel $tournament, string $email, ParticipantStatus $status = ParticipantStatus::Registered): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Berta',
            'guest_email' => $email,
            'status' => $status->value,
            'registered_at' => $this->now,
            'email_confirmed_at' => $this->now->subDay(),
        ]);
    }

    private function userRow(TournamentModel $tournament): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => $this->now,
        ]);
    }

    /**
     * @return Testable<ParticipantsRelationManager>
     */
    private function manager(TournamentModel $tournament): Testable
    {
        return Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class]);
    }

    public function test_a_guest_added_from_the_panel_is_normalised_and_confirmed(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::InProgress->value]);

        $this->manager($tournament)
            ->callTableAction('create', data: [
                'guest_name' => 'Ana',
                'guest_email' => 'ANA@Example.com',
                'status' => ParticipantStatus::Registered->value,
            ])
            ->assertHasNoTableActionErrors();

        $row = ParticipantModel::query()->where('tournament_id', $tournament->id)->sole();
        $this->assertSame('ana@example.com', $row->guest_email);
        $this->assertSame('Ana', $row->guest_name);
        $this->assertSame(ParticipantStatus::Registered, $row->status);
        $this->assertEquals($this->now, $row->email_confirmed_at);
        $this->assertNull($row->fingerprint);
        $this->assertNull($row->confirmation_token);
        Event::assertNotDispatched(ParticipantRegistered::class);
        Event::assertNotDispatched(GuestConfirmationRequested::class);
    }

    public function test_adding_an_email_already_registered_shows_the_refusal(): void
    {
        $tournament = $this->tournament();
        $this->pendingGuest($tournament, 'ana@example.com');

        $this->manager($tournament)
            ->callTableAction('create', data: [
                'guest_name' => 'Ana',
                'guest_email' => 'Ana@Example.com',
                'status' => ParticipantStatus::Registered->value,
            ])
            ->assertNotified('Ya hay una inscripción con este email.');

        $this->assertSame(1, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_adding_a_user_already_registered_shows_the_refusal(): void
    {
        $tournament = $this->tournament();
        $existing = $this->userRow($tournament);

        $this->manager($tournament)
            ->callTableAction('create', data: [
                'user_id' => $existing->user_id,
                'status' => ParticipantStatus::Registered->value,
            ])
            ->assertNotified('Este usuario ya tiene una inscripción en este torneo.');

        $this->assertSame(1, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_adding_to_a_full_tournament_shows_the_refusal(): void
    {
        $tournament = $this->tournament(['max_participants' => 1]);
        $this->confirmedGuest($tournament, 'berta@example.com');

        $this->manager($tournament)
            ->callTableAction('create', data: [
                'guest_name' => 'Ana',
                'guest_email' => 'ana@example.com',
                'status' => ParticipantStatus::Registered->value,
            ])
            ->assertNotified('El torneo está completo');

        $this->assertSame(1, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_the_confirm_email_action_is_offered_only_for_unconfirmed_guests(): void
    {
        $tournament = $this->tournament();
        $pending = $this->pendingGuest($tournament, 'ana@example.com');
        $confirmed = $this->confirmedGuest($tournament, 'berta@example.com');
        $user = $this->userRow($tournament);
        $expired = $this->pendingGuest($tournament, 'carla@example.com');
        $expired->update(['status' => ParticipantStatus::Expired->value]);

        $this->manager($tournament)
            ->assertTableActionVisible('confirm_email', $pending)
            ->assertTableActionHidden('confirm_email', $confirmed)
            ->assertTableActionHidden('confirm_email', $user)
            ->assertTableActionHidden('confirm_email', $expired);
    }

    public function test_marking_the_email_confirmed_from_the_panel(): void
    {
        $tournament = $this->tournament();
        $pending = $this->pendingGuest($tournament, 'ana@example.com');

        $this->manager($tournament)
            ->callTableAction('confirm_email', $pending)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Email marcado como confirmado');

        $pending->refresh();
        $this->assertEquals($this->now, $pending->email_confirmed_at);
        $this->assertSame(ParticipantStatus::Registered, $pending->status);
        Event::assertDispatchedTimes(ParticipantRegistered::class, 1);
        Event::assertDispatched(ParticipantRegistered::class, fn (ParticipantRegistered $event): bool => $event->participantId === $pending->id
            && ! $event->notifyOrganizer);
    }

    public function test_the_filter_returns_only_unconfirmed_guests(): void
    {
        $tournament = $this->tournament();
        $pending = $this->pendingGuest($tournament, 'ana@example.com');
        $confirmed = $this->confirmedGuest($tournament, 'berta@example.com');
        $user = $this->userRow($tournament);

        $this->manager($tournament)
            ->filterTable('email_confirmed', false)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$confirmed, $user])
            ->filterTable('email_confirmed', true)
            ->assertCanSeeTableRecords([$confirmed, $user])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_the_status_tells_an_unconfirmed_email_apart(): void
    {
        $tournament = $this->tournament();
        $hold = $this->pendingGuest($tournament, 'ana@example.com');
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $wait = $this->pendingGuest($tournament, 'carla@example.com');
        $confirmed = $this->confirmedGuest($tournament, 'berta@example.com');

        $this->manager($tournament)
            ->assertTableColumnHasDescription('status', 'Email sin confirmar · caduca el 02/11/2026 12:00', $hold)
            ->assertTableColumnHasDescription('status', 'Email sin confirmar · sin plaza hasta confirmar', $wait)
            ->assertTableColumnHasDescription('status', null, $confirmed);
    }

    public function test_guests_sharing_a_fingerprint_are_possible_duplicates(): void
    {
        $tournament = $this->tournament();
        $fingerprint = str_repeat('f', 64);
        $first = $this->pendingGuest($tournament, 'ana@example.com', $fingerprint);
        $second = $this->pendingGuest($tournament, 'ana.otra@example.com', $fingerprint);
        $other = $this->pendingGuest($tournament, 'carla@example.com', str_repeat('c', 64));

        $this->manager($tournament)
            ->assertTableColumnStateSet('possible_duplicate', true, $first)
            ->assertTableColumnStateSet('possible_duplicate', true, $second)
            ->assertTableColumnStateSet('possible_duplicate', false, $other);
    }

    public function test_bulk_confirm_mails_only_the_guests_whose_email_was_unconfirmed(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $first = $this->pendingGuest($tournament, 'ana@example.com');
        $second = $this->pendingGuest($tournament, 'carla@example.com');
        $confirmed = $this->confirmedGuest($tournament, 'berta@example.com');

        $this->manager($tournament)
            ->callTableBulkAction('confirm_selected', [$first, $second, $confirmed])
            ->assertHasNoTableBulkActionErrors();

        foreach ([$first, $second, $confirmed] as $row) {
            $row->refresh();
            $this->assertSame(ParticipantStatus::Confirmed, $row->status);
            $this->assertNotNull($row->email_confirmed_at);
        }
        Event::assertDispatchedTimes(ParticipantRegistered::class, 2);
        Event::assertNotDispatched(ParticipantRegistered::class, fn (ParticipantRegistered $event): bool => $event->participantId === $confirmed->id);
    }

    public function test_check_in_from_the_panel_confirms_the_email_silently(): void
    {
        $tournament = $this->tournament();
        $pending = $this->pendingGuest($tournament, 'ana@example.com');
        $other = $this->pendingGuest($tournament, 'carla@example.com');

        $this->manager($tournament)
            ->callTableAction('check_in', $pending)
            ->assertHasNoTableActionErrors()
            ->callTableBulkAction('check_in_selected', [$other])
            ->assertHasNoTableBulkActionErrors();

        foreach ([$pending, $other] as $row) {
            $row->refresh();
            $this->assertSame(ParticipantStatus::CheckedIn, $row->status);
            $this->assertEquals($this->now, $row->email_confirmed_at);
        }
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_disqualifying_from_the_panel_confirms_the_email_silently(): void
    {
        $tournament = $this->tournament();
        $pending = $this->pendingGuest($tournament, 'ana@example.com');
        $other = $this->pendingGuest($tournament, 'carla@example.com');

        $this->manager($tournament)
            ->callTableAction('disqualify', $pending)
            ->assertHasNoTableActionErrors()
            ->callTableBulkAction('disqualify_selected', [$other])
            ->assertHasNoTableBulkActionErrors();

        foreach ([$pending, $other] as $row) {
            $row->refresh();
            $this->assertSame(ParticipantStatus::Disqualified, $row->status);
            $this->assertEquals($this->now, $row->email_confirmed_at);
        }
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_withdrawing_from_the_panel_leaves_the_email_unconfirmed(): void
    {
        $tournament = $this->tournament();
        $pending = $this->pendingGuest($tournament, 'ana@example.com');

        $this->manager($tournament)->callTableAction('withdraw', $pending);

        $pending->refresh();
        $this->assertSame(ParticipantStatus::Withdrawn, $pending->status);
        $this->assertNull($pending->email_confirmed_at);
        Event::assertNotDispatched(ParticipantRegistered::class);
    }

    public function test_a_wait_row_in_a_full_tournament_cannot_be_checked_in(): void
    {
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);
        $tournament = $this->tournament(['max_participants' => 1]);
        $wait = $this->pendingGuest($tournament, 'ana@example.com');
        $this->confirmedGuest($tournament, 'berta@example.com');

        $this->manager($tournament)
            ->callTableAction('check_in', $wait)
            ->assertNotified('El torneo está completo');

        $wait->refresh();
        $this->assertSame(ParticipantStatus::Registered, $wait->status);
        $this->assertNull($wait->email_confirmed_at);
    }

    public function test_an_expired_registration_can_be_deleted(): void
    {
        $tournament = $this->tournament();
        $expired = $this->pendingGuest($tournament, 'ana@example.com');
        $expired->update(['status' => ParticipantStatus::Expired->value]);

        $this->manager($tournament)
            ->assertTableActionVisible('delete', $expired)
            ->callTableAction('delete', $expired);

        $this->assertNull(ParticipantModel::query()->find($expired->id));
    }

    public function test_the_check_in_action_follows_the_manual_confirmation_rule(): void
    {
        $open = $this->tournament();
        $registered = $this->confirmedGuest($open, 'ana@example.com');
        $this->manager($open)->assertTableActionVisible('check_in', $registered);

        $manual = $this->tournament(['requires_manual_confirmation' => true]);
        $pending = $this->confirmedGuest($manual, 'berta@example.com');
        $confirmed = $this->confirmedGuest($manual, 'carla@example.com', ParticipantStatus::Confirmed);
        $this->manager($manual)
            ->assertTableActionHidden('check_in', $pending)
            ->assertTableActionVisible('check_in', $confirmed);
    }

    /**
     * @return iterable<string, array{ParticipantStatus}>
     */
    public static function inactiveStatuses(): iterable
    {
        yield 'expired' => [ParticipantStatus::Expired];
        yield 'withdrawn' => [ParticipantStatus::Withdrawn];
        yield 'disqualified' => [ParticipantStatus::Disqualified];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_a_participant_cannot_be_added_with_an_inactive_status(ParticipantStatus $status): void
    {
        $tournament = $this->tournament();

        $this->manager($tournament)
            ->callTableAction('create', data: [
                'guest_name' => 'Ana',
                'guest_email' => 'ana@example.com',
                'status' => $status->value,
            ])
            ->assertHasTableActionErrors(['status']);

        $this->assertSame(0, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_a_participant_can_be_added_already_checked_in(): void
    {
        $tournament = $this->tournament();

        $this->manager($tournament)
            ->callTableAction('create', data: [
                'guest_name' => 'Ana',
                'guest_email' => 'ana@example.com',
                'status' => ParticipantStatus::CheckedIn->value,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ParticipantStatus::CheckedIn, ParticipantModel::query()->where('tournament_id', $tournament->id)->sole()->status);
    }
}
