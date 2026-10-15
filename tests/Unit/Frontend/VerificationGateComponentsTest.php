<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The module has no Vue test runner, so this reads the sources of the pages and the event
 * section that sign up, withdraw and check in a signed-in user. While the account must verify
 * its email (the core's useVerificationGate), each of those buttons is disabled with the
 * reason tied to it, the handler sends nothing, and a verification 403 that arrives anyway
 * (state changed in another tab) leads to the notice instead of an error message.
 */
final class VerificationGateComponentsTest extends TestCase
{
    private const string JS = __DIR__ . '/../../../resources/js/';

    /**
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function components(): array
    {
        return [
            'tournament page' => [
                'pages/Tournaments/Show.vue',
                ['handleRegister', 'handleWithdraw', 'confirmWithdraw'],
                ['register', 'withdraw'],
            ],
            'event tournament section' => [
                'components/EventTournamentSection.vue',
                ['handleRegister', 'handleWithdraw', 'confirmWithdraw'],
                ['register', 'withdraw'],
            ],
            'check-in page' => [
                'pages/Tournaments/CheckIn.vue',
                ['handleAuthenticatedCheckIn'],
                ['checkIn'],
            ],
        ];
    }

    /**
     * @param  list<string>  $handlers
     * @param  list<string>  $actions
     */
    #[DataProvider('components')]
    public function test_it_uses_the_core_verification_gate(string $file, array $handlers, array $actions): void
    {
        $source = $this->source($file);

        $this->assertStringContainsString(
            "import { useVerificationGate } from '@/composables/useVerificationGate';",
            $source,
        );
        $this->assertStringContainsString('const gate = useVerificationGate();', $source);
    }

    /**
     * @param  list<string>  $handlers
     * @param  list<string>  $actions
     */
    #[DataProvider('components')]
    public function test_every_signed_in_handler_sends_nothing_while_blocked(string $file, array $handlers, array $actions): void
    {
        $source = $this->source($file);

        foreach ($handlers as $handler) {
            $pattern = '/const ' . $handler . ' = (?:async )?\(\): (?:Promise<void>|void) => \{\s*'
                . 'if \(gate\.blocked\.value\) \{\s*return;\s*\}/';

            $this->assertMatchesRegularExpression($pattern, $source, "{$handler}() returns first while blocked");
        }
    }

    /**
     * @param  list<string>  $handlers
     * @param  list<string>  $actions
     */
    #[DataProvider('components')]
    public function test_every_request_leads_to_the_notice_on_a_verification_403(string $file, array $handlers, array $actions): void
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

    /**
     * @param  list<string>  $handlers
     * @param  list<string>  $actions
     */
    #[DataProvider('components')]
    public function test_blocked_buttons_are_disabled_with_their_reason_and_a_link_to_the_notice(string $file, array $handlers, array $actions): void
    {
        $source = $this->source($file);

        // Ids unique per instance: the section and the page are separate components
        $this->assertMatchesRegularExpression('/const \w+ = useId\(\);/', $source);

        foreach ($actions as $action) {
            $this->assertStringContainsString("gate.reason('{$action}')", $source, "Reason for {$action}");
        }

        $this->assertMatchesRegularExpression('/:disabled="[^"]*gate\.blocked\.value[^"]*"/', $source);
        $this->assertMatchesRegularExpression('/:aria-describedby="gate\.blocked\.value \? \w+ : undefined"/', $source);
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
