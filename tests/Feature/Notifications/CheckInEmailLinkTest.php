<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\ParticipantConfirmedNotification;
use Modules\Tournaments\Notifications\ParticipantRegisteredToParticipant;
use Modules\Tournaments\Notifications\TournamentReminderNotification;
use ReflectionClass;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The registration, approval and reminder emails of a tournament with check-in say when it opens
 * and, when participants check in themselves, link to its page; a guest has no other way to it.
 */
final class CheckInEmailLinkTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('es');
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_the_registration_email_links_to_the_check_in(): void
    {
        $message = $this->registration(selfCheckInAllowed: true);

        $this->assertContains($this->selfLine(), $message->introLines);
    }

    public function test_the_approval_email_links_to_the_check_in(): void
    {
        $message = (new ParticipantConfirmedNotification('John', 'Torneo de Magic', null, true, 45, 'torneo-de-magic', selfCheckInAllowed: true))
            ->toMail($this->recipient());

        $this->assertContains($this->selfLine(), $message->introLines);
    }

    public function test_the_reminder_links_to_the_check_in(): void
    {
        $message = (new TournamentReminderNotification('John', 'Torneo de Magic', 'torneo-de-magic', '02/11/2026 20:00', true, 45, selfCheckInAllowed: true))
            ->toMail($this->recipient());

        $this->assertContains($this->selfLine(), $message->introLines);
    }

    public function test_without_self_check_in_the_email_sends_them_to_the_organization(): void
    {
        $line = 'El check-in abre 45 minutos antes del inicio del evento: díselo a la organización al llegar.';

        $this->assertContains($line, $this->registration(selfCheckInAllowed: false)->introLines);
        $this->assertContains($line, (new ParticipantConfirmedNotification('John', 'Torneo de Magic', null, true, 45, 'torneo-de-magic', selfCheckInAllowed: false))->toMail($this->recipient())->introLines);
        $this->assertContains($line, (new TournamentReminderNotification('John', 'Torneo de Magic', 'torneo-de-magic', '02/11/2026 20:00', true, 45, selfCheckInAllowed: false))->toMail($this->recipient())->introLines);
    }

    public function test_an_email_queued_before_the_link_existed_keeps_the_old_reminder(): void
    {
        $queued = $this->queuedWithout(new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, true, 45, null, 'torneo-de-magic', selfCheckInAllowed: true), 'selfCheckInAllowed');

        $message = $queued->toMail($this->recipient());

        $this->assertContains('Recuerda que deberás hacer check-in 45 minutos antes del inicio del evento.', $message->introLines);
    }

    public function test_no_check_in_line_when_the_tournament_does_not_ask_for_it(): void
    {
        $message = (new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, false, null, null, 'torneo-de-magic', selfCheckInAllowed: true))
            ->toMail($this->recipient());

        $this->assertStringNotContainsString('check-in', implode(' ', $message->introLines));
    }

    private function registration(bool $selfCheckInAllowed): MailMessage
    {
        return (new ParticipantRegisteredToParticipant('Torneo de Magic', 'John', null, true, 45, null, 'torneo-de-magic', selfCheckInAllowed: $selfCheckInAllowed))
            ->toMail($this->recipient());
    }

    private function selfLine(): string
    {
        return 'El check-in abre 45 minutos antes del inicio del evento y cierra cuando empieza. Puedes hacerlo en '.route('tournaments.check-in.show', 'torneo-de-magic');
    }

    private function recipient(): AnonymousNotifiable
    {
        return new AnonymousNotifiable('john.doe@example.com', 'John');
    }

    /**
     * The notification as the queue rebuilds one serialized by an older version.
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
