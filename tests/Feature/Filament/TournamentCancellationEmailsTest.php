<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
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
 * known to be theirs; disqualifying someone tells that participant.
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
        $this->now = CarbonImmutable::parse('2026-11-02 10:00:00');
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
