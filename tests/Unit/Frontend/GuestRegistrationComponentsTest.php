<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source guards, not behaviour tests: the module has no Vue test runner. Both guest modals warn
 * with the server's terms, remember the registration and show the server's answers; the cookie is
 * only touched with the visitor's consent.
 */
final class GuestRegistrationComponentsTest extends TestCase
{
    private const string JS = __DIR__.'/../../../resources/js/';

    /**
     * @return array<string, array{string}>
     */
    public static function guestModals(): array
    {
        return [
            'tournament page' => ['pages/Tournaments/Show.vue'],
            'event tournament section' => ['components/EventTournamentSection.vue'],
        ];
    }

    #[DataProvider('guestModals')]
    public function test_the_modal_warns_before_sending_and_remembers_the_registration(string $file): void
    {
        $source = $this->source($file);

        $this->assertStringContainsString('useGuestRegistrationMemory', $source);
        $this->assertStringContainsString("t('tournaments.public.guest_confirmation_hold'", $source);
        $this->assertStringContainsString("t('tournaments.public.guest_confirmation_wait'", $source);
        $this->assertStringContainsString("t('tournaments.public.already_registered_here'", $source);
        $this->assertStringContainsString('remember(', $source);
        $this->assertStringContainsString('props.guestConfirmation', $source);
    }

    #[DataProvider('guestModals')]
    public function test_the_modal_shows_the_server_message_not_its_own(string $file): void
    {
        $source = $this->source($file);

        $this->assertStringContainsString('notifySuccess(data.message', $source);
        $this->assertStringContainsString('login_url', $source);
        $this->assertStringContainsString("t('tournaments.public.sign_in'", $source);
    }

    public function test_the_memory_checks_consent_before_touching_the_cookie(): void
    {
        $source = $this->source('composables/useGuestRegistrationMemory.ts');

        $this->assertStringContainsString('tn_guest_registrations', $source);
        $this->assertStringContainsString("from '@/contracts/cookieConsent'", $source);
        $this->assertStringContainsString("hasCookieConsent('preferences')", $source);
        $this->assertStringNotContainsString('GuildForgeCookieConsent', $source);

        $consent = strpos($source, "hasCookieConsent('preferences')");
        $cookie = strpos($source, 'document.cookie');
        $this->assertNotFalse($consent);
        $this->assertNotFalse($cookie);
        $this->assertLessThan($cookie, $consent);
    }

    public function test_the_build_shares_the_core_public_api_instead_of_bundling_it(): void
    {
        // The core's build keeps its public API out of the bundle: a bundled copy of the
        // consent contract would never hear from the provider, and a bundled useNotifications
        // would write toasts nobody renders
        $config = file_get_contents(__DIR__.'/../../../vite.config.ts');
        $this->assertNotFalse($config);

        $this->assertStringContainsString("from '../../vite.module.config.ts'", $config);
        $this->assertStringContainsString("defineModuleConfig({ name: 'tournaments', root: import.meta.dirname })", $config);
    }

    public function test_the_page_types_carry_the_confirmation_terms(): void
    {
        $source = $this->source('types/tournaments.ts');

        $this->assertStringContainsString("mode: 'hold' | 'wait'", $source);
        $this->assertSame(2, substr_count($source, 'guestConfirmation: GuestConfirmationTerms | null;'));
        $this->assertStringContainsString('login_url?: string;', $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['es' => ['locales/es.ts'], 'en' => ['locales/en.ts']];
    }

    #[DataProvider('locales')]
    public function test_every_guest_key_exists_in_the_locale(string $locale): void
    {
        $source = $this->source($locale);

        foreach (['guest_confirmation_hold', 'guest_confirmation_wait', 'already_registered_here', 'sign_in'] as $key) {
            $this->assertMatchesRegularExpression('/\b'.$key.':/', $source, "{$locale} lacks {$key}");
        }
    }

    public function test_the_spanish_texts_are_the_agreed_ones(): void
    {
        $source = $this->source('locales/es.ts');

        $this->assertStringContainsString('Te enviaremos un email para confirmar la inscripción. Si no la confirmas antes del {date}, la plaza se liberará.', $source);
        $this->assertStringContainsString('Te enviaremos un email para confirmar la inscripción. Tu plaza no está garantizada hasta que la confirmes.', $source);
        $this->assertStringContainsString('Ya hay una inscripción desde este navegador para este torneo.', $source);
    }

    private function source(string $file): string
    {
        $contents = file_get_contents(self::JS.$file);
        $this->assertNotFalse($contents, "{$file} is readable");

        return $contents;
    }
}
