<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source guards, not behaviour tests: the module has no Vue test runner, so this reads the
 * sources of the pages and the event section that sign up, withdraw and check in a signed-in
 * user. While the account must verify its email (the core's useVerificationGate), each of
 * those buttons is disabled with the reason tied to it, the handler sends nothing, and a
 * verification 403 that arrives anyway (state changed in another tab) closes any open dialog
 * and leads to the notice instead of an error message.
 */
final class VerificationGateComponentsTest extends TestCase
{
    private const string JS = __DIR__ . '/../../../resources/js/';

    /**
     * Per file: the handlers that act as the signed-in user, the gate actions whose reason is
     * shown, and how many buttons the gate disables.
     *
     * @var array<string, array{handlers: list<string>, actions: list<string>, buttons: int}>
     */
    private const array GATED = [
        'pages/Tournaments/Show.vue' => [
            'handlers' => ['handleRegister', 'handleWithdraw', 'confirmWithdraw'],
            'actions' => ['register', 'withdraw'],
            'buttons' => 2,
        ],
        'components/EventTournamentSection.vue' => [
            'handlers' => ['handleRegister', 'handleWithdraw', 'confirmWithdraw'],
            'actions' => ['register', 'withdraw'],
            'buttons' => 2,
        ],
        'pages/Tournaments/CheckIn.vue' => [
            'handlers' => ['handleAuthenticatedCheckIn'],
            'actions' => ['checkIn'],
            'buttons' => 1,
        ],
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function components(): array
    {
        return [
            'tournament page' => ['pages/Tournaments/Show.vue'],
            'event tournament section' => ['components/EventTournamentSection.vue'],
            'check-in page' => ['pages/Tournaments/CheckIn.vue'],
        ];
    }

    #[DataProvider('components')]
    public function test_it_uses_the_core_verification_gate(string $file): void
    {
        $source = $this->source($file);

        $this->assertStringContainsString(
            "import { useVerificationGate } from '@/composables/useVerificationGate';",
            $source,
        );
        $this->assertStringContainsString('const gate = useVerificationGate();', $source);
    }

    #[DataProvider('components')]
    public function test_every_signed_in_handler_sends_nothing_while_blocked(string $file): void
    {
        $source = $this->source($file);

        foreach (self::GATED[$file]['handlers'] as $handler) {
            $pattern = '/const ' . $handler . ' = (?:async )?\(\): (?:Promise<void>|void) => \{\s*'
                . 'if \(gate\.blocked\.value\) \{\s*return;\s*\}/';

            $this->assertMatchesRegularExpression($pattern, $source, "{$handler}() returns first while blocked");
        }
    }

    #[DataProvider('components')]
    public function test_every_request_leads_to_the_notice_on_a_verification_403(string $file): void
    {
        $source = $this->source($file);

        $requests = preg_match_all('/await fetch\(/', $source);
        $checks = preg_match_all(
            '/if \(gate\.isVerificationRequired\(response\)\) \{[^}]*gate\.goToNotice\(\);\s*return;\s*\}/',
            $source,
        );

        $this->assertGreaterThan(0, $requests);
        $this->assertSame($requests, $checks, 'Each fetch() checks for the verification 403 and goes to the notice');
    }

    #[DataProvider('components')]
    public function test_an_open_dialog_is_closed_before_going_to_the_notice(string $file): void
    {
        $source = $this->source($file);

        $dialogs = [
            'showGuestModal' => 'submitGuestRegistration',
            'showWithdrawConfirm' => 'confirmWithdraw',
        ];

        foreach ($dialogs as $flag => $handler) {
            if (! str_contains($source, "const {$flag} = ref(false);")) {
                continue;
            }

            $pattern = '/const ' . $handler . ' = async \(\): Promise<void> => \{'
                . '(?:(?!\nconst ).)*?if \(gate\.isVerificationRequired\(response\)\) \{\s*'
                . $flag . '\.value = false;\s*gate\.goToNotice\(\);/s';

            $this->assertMatchesRegularExpression($pattern, $source, "{$handler}() closes {$flag} first");
        }
    }

    #[DataProvider('components')]
    public function test_every_gated_button_is_disabled_and_described_by_a_rendered_reason(string $file): void
    {
        $source = $this->source($file);
        $buttons = self::GATED[$file]['buttons'];

        $this->assertSame(
            $buttons,
            preg_match_all('/:disabled="[^"]*gate\.blocked\.value[^"]*"/', $source),
            'Every gated button is disabled while blocked',
        );

        preg_match_all('/:aria-describedby="gate\.blocked\.value \? (\w+) : undefined"/', $source, $matches);
        $this->assertCount($buttons, $matches[1], 'Every gated button is described by the reason');

        // Ids unique per instance: the section and the page are separate components
        foreach (array_unique($matches[1]) as $id) {
            $this->assertStringContainsString("const {$id} = useId();", $source);
            $this->assertSame(1, substr_count($source, ":id=\"{$id}\""), "One reason element carries :id=\"{$id}\"");
        }
    }

    #[DataProvider('components')]
    public function test_the_reason_names_the_action_and_links_to_the_notice(string $file): void
    {
        $source = $this->source($file);

        foreach (self::GATED[$file]['actions'] as $action) {
            $this->assertStringContainsString("gate.reason('{$action}')", $source, "Reason for {$action}");
        }

        $this->assertStringContainsString(':href="gate.noticeUrl"', $source);
        $this->assertStringContainsString('{{ gate.linkLabel.value }}', $source);
    }

    private function source(string $file): string
    {
        $source = file_get_contents(self::JS . $file);
        $this->assertIsString($source);

        return $source;
    }
}
