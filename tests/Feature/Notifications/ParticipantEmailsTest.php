<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Notifications;

use Modules\Tournaments\Notifications\AnonymousNotifiable;
use Modules\Tournaments\Notifications\ParticipantRegisteredToParticipant;
use Modules\Tournaments\Notifications\ParticipantWithdrawnToParticipant;
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
        $message = (new ParticipantRegisteredToParticipant('Torneo de Magic', 'Ana', '02/11/2026 20:00', false, null, null, 'torneo-de-magic'))
            ->toMail(new AnonymousNotifiable('ana@example.com', 'Ana'));

        $this->assertSame('¡Hola, Ana!', $message->greeting);
        $this->assertSame(route('tournaments.show', 'torneo-de-magic'), $message->actionUrl);
    }

    public function test_the_registration_email_of_a_guest_keeps_the_cancellation_button_and_links_to_the_tournament(): void
    {
        $message = (new ParticipantRegisteredToParticipant('Torneo de Magic', 'Ana', null, false, null, 'token-de-prueba', 'torneo-de-magic'))
            ->toMail(new AnonymousNotifiable('ana@example.com', 'Ana'));

        $this->assertSame(route('tournaments.cancel-confirmation', 'token-de-prueba'), $message->actionUrl);
        $this->assertStringContainsString(route('tournaments.show', 'torneo-de-magic'), implode(' ', [...$message->introLines, ...$message->outroLines]));
    }

    public function test_the_withdrawal_email_does_not_claim_the_data_was_deleted(): void
    {
        $message = (new ParticipantWithdrawnToParticipant('Ana', 'Torneo de Magic', null, 'torneo-de-magic'))
            ->toMail(new AnonymousNotifiable('ana@example.com', 'Ana'));
        $text = implode(' ', [...$message->introLines, ...$message->outroLines]);

        $this->assertStringNotContainsString('eliminados', $text);
        $this->assertSame('¡Hola, Ana!', $message->greeting);
        $this->assertSame(route('tournaments.show', 'torneo-de-magic'), $message->actionUrl);
    }
}
