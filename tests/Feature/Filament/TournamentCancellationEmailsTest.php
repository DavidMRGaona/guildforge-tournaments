<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\GameProfileResource;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Filament\Resources\TournamentResource\Pages\EditTournament;
use Modules\Tournaments\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Cancelling a tournament from the panel tells every active participant whose email is
 * known to be theirs; disqualifying someone tells that participant, with the reason if given;
 * approving a registration tells them too, with the organisation's notes if given.
 */
final class TournamentCancellationEmailsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Madrid');
        $this->travelTo($this->now);
        config()->set('modules.settings.tournaments', []);

        $this->registerOnAdminPanel([GameProfileResource::class, TournamentResource::class]);
        $this->actingAsPanelAdmin();
    }

    public function test_cancelling_a_tournament_emails_its_active_participants(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create(['email' => 'cuenta@example.com']);
        $this->participant($tournament, ['user_id' => $user->id, 'status' => ParticipantStatus::Confirmed->value]);
        $this->participant($tournament, ['guest_name' => 'Invitada', 'guest_email' => 'invitada@example.com', 'email_confirmed_at' => $this->now]);
        $this->participant($tournament, ['guest_name' => 'Sin confirmar', 'guest_email' => 'pendiente@example.com', 'email_confirmed_at' => null]);
        $this->participant($tournament, ['guest_name' => 'Retirada', 'guest_email' => 'retirada@example.com', 'email_confirmed_at' => $this->now, 'status' => ParticipantStatus::Withdrawn->value]);

        Livewire::test(EditTournament::class, ['record' => $tournament->id])
            ->callAction('cancelTournament')
            ->assertHasNoActionErrors();

        $this->assertSame(TournamentStatus::Cancelled, $tournament->fresh()?->status);
        $mails = $this->mails();
        $this->assertEqualsCanonicalizing(['cuenta@example.com', 'invitada@example.com'], array_keys($mails));
        $this->assertSame('Torneo cancelado: Torneo de prueba', $mails['invitada@example.com']['subject']);
        $this->assertStringContainsString('ha cancelado el torneo', $mails['invitada@example.com']['body']);
    }

    public function test_cancelling_a_tournament_without_participants_sends_nothing(): void
    {
        $tournament = $this->tournament();

        Livewire::test(EditTournament::class, ['record' => $tournament->id])->callAction('cancelTournament');

        $this->assertSame([], $this->mails());
    }

    public function test_disqualifying_a_participant_emails_them(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::InProgress->value]);
        $user = UserModel::factory()->create(['email' => 'cuenta@example.com']);
        $row = $this->participant($tournament, ['user_id' => $user->id, 'status' => ParticipantStatus::CheckedIn->value]);

        Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class])
            ->callTableAction('disqualify', $row);

        $this->assertSame(ParticipantStatus::Disqualified, $row->fresh()?->status);
        $mails = $this->mails();
        $this->assertSame(['cuenta@example.com'], array_keys($mails));
        $this->assertSame('Descalificación: Torneo de prueba', $mails['cuenta@example.com']['subject']);
    }

    public function test_disqualifying_a_guest_who_never_confirmed_the_email_sends_nothing(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::InProgress->value]);
        $row = $this->participant($tournament, ['guest_name' => 'Sin confirmar', 'guest_email' => 'pendiente@example.com', 'email_confirmed_at' => null]);

        Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class])
            ->callTableAction('disqualify', $row);

        $this->assertSame(ParticipantStatus::Disqualified, $row->fresh()?->status);
        $this->assertSame([], $this->mails());
    }

    public function test_the_disqualification_email_carries_the_reason(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::InProgress->value]);
        $user = UserModel::factory()->create(['email' => 'cuenta@example.com']);
        $row = $this->participant($tournament, ['user_id' => $user->id, 'status' => ParticipantStatus::CheckedIn->value]);

        $this->manager($tournament)
            ->callTableAction('disqualify', $row, data: ['reason' => 'No se presentó a la segunda ronda'])
            ->assertHasNoTableActionErrors();

        $mails = $this->mails();
        $this->assertSame(['cuenta@example.com'], array_keys($mails));
        $this->assertStringContainsString('Motivo', $mails['cuenta@example.com']['body']);
        $this->assertStringContainsString('No se presentó a la segunda ronda', $mails['cuenta@example.com']['body']);
    }

    public function test_the_bulk_disqualification_sends_the_reason_to_each_participant(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::InProgress->value]);
        $first = $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'email_confirmed_at' => $this->now]);
        $second = $this->participant($tournament, ['guest_name' => 'Carla', 'guest_email' => 'carla@example.com', 'email_confirmed_at' => $this->now]);

        $this->manager($tournament)
            ->callTableBulkAction('disqualify_selected', [$first, $second], data: ['reason' => 'Listas de ejército no válidas'])
            ->assertHasNoTableBulkActionErrors();

        $mails = $this->mails();
        $this->assertEqualsCanonicalizing(['ana@example.com', 'carla@example.com'], array_keys($mails));
        foreach ($mails as $mail) {
            $this->assertStringContainsString('Listas de ejército no válidas', $mail['body']);
        }
    }

    public function test_the_disqualification_email_leaves_out_a_blank_reason(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::InProgress->value]);
        $user = UserModel::factory()->create(['email' => 'cuenta@example.com']);
        $row = $this->participant($tournament, ['user_id' => $user->id, 'status' => ParticipantStatus::CheckedIn->value]);

        $this->manager($tournament)->callTableAction('disqualify', $row, data: ['reason' => '   ']);

        $this->assertStringNotContainsString('Motivo', $this->mails()['cuenta@example.com']['body']);
    }

    public function test_registering_where_the_organisation_must_approve_says_it_is_pending(): void
    {
        $manual = $this->tournament(['requires_manual_confirmation' => true, 'notification_email' => '']);
        $open = $this->tournament(['notification_email' => '']);
        $first = UserModel::factory()->create(['email' => 'pendiente@example.com']);
        $second = UserModel::factory()->create(['email' => 'directa@example.com']);

        $service = app(ParticipantManagementServiceInterface::class);
        $service->register(new RegisterParticipantDTO(tournamentId: $manual->id, userId: $first->id));
        $service->register(new RegisterParticipantDTO(tournamentId: $open->id, userId: $second->id));

        $mails = $this->mails();
        $this->assertSame('Inscripción recibida: Torneo de prueba', $mails['pendiente@example.com']['subject']);
        $this->assertSame('Inscripción confirmada: Torneo de prueba', $mails['directa@example.com']['subject']);
    }

    public function test_approving_a_registration_emails_the_participant_with_the_notes(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $user = UserModel::factory()->create(['email' => 'cuenta@example.com']);
        $row = $this->participant($tournament, ['user_id' => $user->id]);

        $this->manager($tournament)
            ->callTableAction('confirm', $row, data: ['notes' => 'Trae tu lista impresa'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(ParticipantStatus::Confirmed, $row->fresh()?->status);
        $mails = $this->mails();
        $this->assertSame(['cuenta@example.com'], array_keys($mails));
        $this->assertSame('Inscripción aprobada: Torneo de prueba', $mails['cuenta@example.com']['subject']);
        $this->assertStringContainsString('Notas de la organización', $mails['cuenta@example.com']['body']);
        $this->assertStringContainsString('Trae tu lista impresa', $mails['cuenta@example.com']['body']);
    }

    public function test_approving_without_notes_still_emails_the_participant(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $row = $this->participant($tournament, ['guest_name' => 'Invitada', 'guest_email' => 'invitada@example.com', 'email_confirmed_at' => $this->now]);

        $this->manager($tournament)->callTableAction('confirm', $row);

        $mails = $this->mails();
        $this->assertSame(['invitada@example.com'], array_keys($mails));
        $this->assertSame('Inscripción aprobada: Torneo de prueba', $mails['invitada@example.com']['subject']);
        $this->assertStringNotContainsString('Notas de la organización', $mails['invitada@example.com']['body']);
    }

    public function test_the_bulk_approval_sends_the_notes_to_each_participant(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $first = $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'email_confirmed_at' => $this->now]);
        $second = $this->participant($tournament, ['guest_name' => 'Carla', 'guest_email' => 'carla@example.com', 'email_confirmed_at' => $this->now]);

        $this->manager($tournament)
            ->callTableBulkAction('confirm_selected', [$first, $second], data: ['notes' => 'Mesa 4'])
            ->assertHasNoTableBulkActionErrors();

        $mails = $this->mails();
        $this->assertEqualsCanonicalizing(['ana@example.com', 'carla@example.com'], array_keys($mails));
        foreach ($mails as $mail) {
            $this->assertSame('Inscripción aprobada: Torneo de prueba', $mail['subject']);
            $this->assertStringContainsString('Mesa 4', $mail['body']);
        }
    }

    public function test_approving_a_guest_whose_email_was_unconfirmed_sends_one_email_with_the_notes(): void
    {
        $tournament = $this->tournament(['requires_manual_confirmation' => true]);
        $row = $this->participant($tournament, ['guest_name' => 'Sin confirmar', 'guest_email' => 'pendiente@example.com', 'email_confirmed_at' => null]);

        $this->manager($tournament)->callTableAction('confirm', $row, data: ['notes' => 'Trae tu lista impresa']);

        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount(1, $transport->messages());
        $mail = $this->mails()['pendiente@example.com'];
        $this->assertSame('Inscripción confirmada: Torneo de prueba', $mail['subject']);
        $this->assertStringContainsString('Trae tu lista impresa', $mail['body']);
    }

    /**
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
     * @param  array<string, mixed>  $attributes
     */
    private function participant(TournamentModel $tournament, array $attributes): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'status' => ParticipantStatus::Registered->value,
            'cancellation_token' => Str::random(64),
            'registered_at' => $this->now,
            ...$attributes,
        ]);
    }

    /**
     * @return Testable<ParticipantsRelationManager>
     */
    private function manager(TournamentModel $tournament): Testable
    {
        return Livewire::test(ParticipantsRelationManager::class, ['ownerRecord' => $tournament, 'pageClass' => EditTournament::class]);
    }

    /**
     * Mails sent through the array mailer, keyed by recipient.
     *
     * @return array<string, array{subject: string, body: string}>
     */
    private function mails(): array
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $mails = [];
        /** @var SentMessage $message */
        foreach ($transport->messages() as $message) {
            $email = $message->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $email);
            $mails[$email->getTo()[0]->getAddress()] = [
                'subject' => (string) $email->getSubject(),
                'body' => (string) $email->getHtmlBody(),
            ];
        }

        return $mails;
    }
}
