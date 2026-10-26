<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Notifications;

use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\ParticipantRegisteredToParticipant;
use Modules\Tournaments\Notifications\ParticipantWithdrawnToParticipant;
use ReflectionClass;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Registration and withdrawal emails link to the tournament and only say what is true.
 */
final class ParticipantEmailsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_the_registration_email_of_an_account_links_to_the_tournament(): void
    {
        $message = (new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', '02/11/2026 20:00', false, null, null, 'torneo-de-magic'))
            ->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));

        $this->assertSame('¡Hola, John!', $message->greeting);
        $this->assertSame(route('tournaments.show', 'torneo-de-magic'), $message->actionUrl);
    }

    public function test_the_registration_email_of_a_guest_keeps_the_cancellation_button_and_links_to_the_tournament(): void
    {
        $message = (new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, false, null, 'token-de-prueba', 'torneo-de-magic'))
            ->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));

        $this->assertSame(route('tournaments.cancel-confirmation', 'token-de-prueba'), $message->actionUrl);
        $this->assertStringContainsString(route('tournaments.show', 'torneo-de-magic'), implode(' ', [...$message->introLines, ...$message->outroLines]));
    }

    public function test_the_withdrawal_email_does_not_claim_the_data_was_deleted(): void
    {
        $message = (new ParticipantWithdrawnToParticipant('John', 'Torneo de Magic', null, 'torneo-de-magic'))
            ->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));
        $text = implode(' ', [...$message->introLines, ...$message->outroLines]);

        $this->assertStringNotContainsString('eliminados', $text);
        $this->assertSame('¡Hola, John!', $message->greeting);
        $this->assertSame(route('tournaments.show', 'torneo-de-magic'), $message->actionUrl);
    }

    public function test_a_registration_email_queued_before_the_tournament_link_existed_is_still_sent(): void
    {
        $message = $this->queuedWithout(
            new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, false, null, null, 'torneo-de-magic'),
            'tournamentSlug',
        )->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));

        $this->assertSame('¡Hola, John!', $message->greeting);
        // MailMessage declares $actionUrl as a string, but it stays null without an action
        $this->assertNull($message->toArray()['actionUrl']);
    }

    public function test_a_withdrawal_email_queued_before_the_tournament_link_existed_is_still_sent(): void
    {
        $message = $this->queuedWithout(
            new ParticipantWithdrawnToParticipant('John', 'Torneo de Magic', null, 'torneo-de-magic'),
            'tournamentSlug',
        )->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));

        // MailMessage declares $actionUrl as a string, but it stays null without an action
        $this->assertNull($message->toArray()['actionUrl']);
    }

    public function test_a_registration_awaiting_approval_says_it_is_pending(): void
    {
        $message = (new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, false, null, null, 'torneo-de-magic', awaitingApproval: true))
            ->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));
        $text = implode(' ', [...$message->introLines, ...$message->outroLines]);

        $this->assertSame('Inscripción recibida: Torneo de Magic', $message->subject);
        $this->assertStringContainsString('tiene que aprobarla', $text);
        $this->assertStringNotContainsString('registrada correctamente', $text);
        $this->assertStringNotContainsString('Te esperamos', $text);
    }

    public function test_the_registration_email_carries_the_organisation_notes(): void
    {
        $message = (new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, false, null, null, 'torneo-de-magic', notes: 'Trae tu lista impresa'))
            ->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));

        $this->assertSame('Inscripción confirmada: Torneo de Magic', $message->subject);
        $this->assertContains('**Notas de la organización:** Trae tu lista impresa', $message->introLines);
    }

    public function test_a_registration_email_queued_before_approvals_existed_is_still_sent(): void
    {
        $message = $this->queuedWithout(
            new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, false, null, null, 'torneo-de-magic', notes: 'Mesa 4', awaitingApproval: true),
            'notes',
            'awaitingApproval',
        )->toMail(new AnonymousNotifiable('john.doe@example.com', 'John'));

        $this->assertSame('Inscripción confirmada: Torneo de Magic', $message->subject);
    }

    /**
     * The notification as the queue rebuilds one serialized by an older version: every
     * property but the given ones, which are left uninitialized.
     *
     * @template TNotification of object
     *
     * @param  TNotification  $notification
     * @return TNotification
     */
    private function queuedWithout(object $notification, string ...$missing): object
    {
        $class = new ReflectionClass($notification);
        $queued = $class->newInstanceWithoutConstructor();

        foreach ($class->getProperties() as $property) {
            if (! in_array($property->getName(), $missing, true) && ! $property->isStatic() && $property->isInitialized($notification)) {
                $property->setValue($queued, $property->getValue($notification));
            }
        }

        return $queued;
    }
}
