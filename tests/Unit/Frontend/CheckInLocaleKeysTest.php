<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source guards, not behaviour tests: the module has no Vue test runner. Every text the check-in
 * page asks for exists in both locales, so the page never shows a raw key.
 */
final class CheckInLocaleKeysTest extends TestCase
{
    private const string JS = __DIR__.'/../../../resources/js/';

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['es' => ['locales/es.ts'], 'en' => ['locales/en.ts']];
    }

    #[DataProvider('locales')]
    public function test_every_key_of_the_check_in_page_exists_in_the_locale(string $locale): void
    {
        $page = $this->source('pages/Tournaments/CheckIn.vue');
        $source = $this->source($locale);

        preg_match_all("/\\bt\\('tournaments\\.([a-z_]+)\\.([a-z_]+)'/", $page, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches, 'the page uses tournament texts');

        foreach ($matches as [, $group, $key]) {
            // A group is a block indented under `tournaments`, its keys one level deeper
            $found = preg_match('/^ {8}'.$group.': \{\n(.*?)^ {8}\},$/ms', $source, $block);
            $this->assertSame(1, $found, "{$locale} lacks the {$group} block");
            $this->assertMatchesRegularExpression('/^ {12}'.$key.':/m', $block[1], "{$locale} lacks {$group}.{$key}");
        }
    }

    public function test_a_failed_request_reports_a_connection_error_not_a_closed_window(): void
    {
        $page = $this->source('pages/Tournaments/CheckIn.vue');

        $this->assertStringNotContainsString("t('tournaments.check_in.window_closed')", $page);
        $this->assertStringContainsString("t('tournaments.check_in.connection_error')", $page);
        $this->assertMatchesRegularExpression(
            "/connection_error:\\s+'No se ha podido completar el check-in\\. Comprueba tu conexión e inténtalo de nuevo\\.'/u",
            $this->source('locales/es.ts'),
        );
    }

    private function source(string $file): string
    {
        $contents = file_get_contents(self::JS.$file);
        $this->assertNotFalse($contents, "{$file} is readable");

        return $contents;
    }
}
