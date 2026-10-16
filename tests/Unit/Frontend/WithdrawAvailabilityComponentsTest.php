<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source guards, not behaviour tests: the module has no Vue test runner. The withdraw buttons
 * follow the server's `can_withdraw` (event not started, tournament not running), not the
 * registration flag, and the guest cancellation page says why it cannot cancel with texts
 * that exist in both locales.
 */
final class WithdrawAvailabilityComponentsTest extends TestCase
{
    private const string JS = __DIR__ . '/../../../resources/js/';

    /**
     * @return array<string, array{string}>
     */
    public static function withdrawingComponents(): array
    {
        return [
            'tournament page' => ['pages/Tournaments/Show.vue'],
            'event tournament section' => ['components/EventTournamentSection.vue'],
        ];
    }

    #[DataProvider('withdrawingComponents')]
    public function test_the_withdraw_button_follows_the_server_flag(string $file): void
    {
        $matched = preg_match('/const canWithdraw = computed\(\(\) => \{(.*?)\n\}\);/s', $this->source($file), $match);

        $this->assertSame(1, $matched, 'canWithdraw is a computed block');
        $this->assertStringContainsString('isRegistered.value && props.tournament.can_withdraw;', $match[1]);
        $this->assertStringNotContainsString('is_registration_open', $match[1]);
    }

    public function test_the_tournament_type_declares_the_withdraw_flag(): void
    {
        $this->assertMatchesRegularExpression(
            '/is_registration_open: boolean;\s*can_withdraw: boolean;/',
            $this->source('types/tournaments.ts'),
        );
    }

    public function test_the_cancellation_page_shows_why_it_cannot_cancel(): void
    {
        $source = $this->source('pages/Tournaments/CancelRegistration.vue');

        $this->assertStringContainsString('cancelBlockedReason: CancelBlockedReason | null;', $source);
        $this->assertStringContainsString("t('tournaments.cancellation.tournament_started')", $source);
        $this->assertStringContainsString("t('tournaments.cancellation.tournament_cancelled')", $source);
        $this->assertStringContainsString('{{ blockedMessage }}', $source);
        $this->assertStringNotContainsString("{{ t('tournaments.cancellation.already_cancelled') }}", $source);
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function locales(): array
    {
        return [
            'es' => ['locales/es.ts', [
                'title' => 'Cancelar inscripción',
                'already_cancelled' => 'Tu inscripción ya ha sido cancelada',
                'tournament_started' => 'El torneo ya ha comenzado: ya no puedes cancelar tu inscripción',
                'tournament_cancelled' => 'El torneo se ha cancelado',
                'confirm_button' => 'Sí, cancelar inscripción',
            ]],
            'en' => ['locales/en.ts', [
                'title' => 'Cancel registration',
                'already_cancelled' => 'Your registration has already been cancelled',
                'tournament_started' => 'The tournament has already started: you can no longer cancel your registration',
                'tournament_cancelled' => 'The tournament has been cancelled',
                'confirm_button' => 'Yes, cancel registration',
            ]],
        ];
    }

    /**
     * @param array<string, string> $literals
     */
    #[DataProvider('locales')]
    public function test_every_cancellation_text_of_the_page_exists_in_the_locale(string $locale, array $literals): void
    {
        preg_match_all("/t\('tournaments\.cancellation\.([a-z_]+)'/", $this->source('pages/Tournaments/CancelRegistration.vue'), $used);
        $matched = preg_match('/\n        cancellation: \{\n(.*?)\n        \},/s', $this->source($locale), $block);

        $this->assertNotEmpty($used[1]);
        $this->assertSame(1, $matched, "{$locale} has a tournaments.cancellation block");

        foreach (array_unique($used[1]) as $key) {
            $this->assertMatchesRegularExpression('/^\s+' . $key . ': /m', $block[1], "tournaments.cancellation.{$key} in {$locale}");
        }
        foreach ($literals as $key => $text) {
            $this->assertStringContainsString("            {$key}: '{$text}',", $block[1], "{$key} text in {$locale}");
        }
    }

    private function source(string $file): string
    {
        $source = file_get_contents(self::JS . $file);
        $this->assertIsString($source);

        return $source;
    }
}
