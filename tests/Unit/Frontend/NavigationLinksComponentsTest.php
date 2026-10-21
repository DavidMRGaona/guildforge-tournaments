<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Source guards, not behaviour tests: the module has no Vue test runner. Every tournament page
 * can be reached by clicking: the profile tab always links the personal list and the tournament
 * page links the check-in when the server says the viewer may check in.
 */
final class NavigationLinksComponentsTest extends TestCase
{
    private const string JS = __DIR__ . '/../../../resources/js/';

    public function test_the_profile_tab_links_the_personal_list_even_without_tournaments(): void
    {
        $source = $this->source('components/profile/ProfileTournamentsSection.vue');

        $this->assertStringContainsString('href="/torneos/mis-torneos"', $source);
        $this->assertSame(1, substr_count($source, 'href="/torneos/mis-torneos"'), 'one link, outside the conditional blocks');

        // The link sits in the wrapper that always renders; only the sections depend on having tournaments
        $this->assertStringContainsString('<template v-if="profileTournaments && hasAnyTournaments">', $source);
        $this->assertMatchesRegularExpression('/<template>\s*<div class="space-y-10">\s*<!--[^>]*-->\s*<div class="flex justify-end">\s*<Link\s+href="\/torneos\/mis-torneos"/', $source);
    }

    public function test_the_tournament_page_links_the_check_in_from_the_server_flag(): void
    {
        $page = $this->source('pages/Tournaments/Show.vue');

        $this->assertMatchesRegularExpression('/v-if="canCheckIn"/', $page);
        $this->assertStringContainsString("/torneos/\${props.tournament.slug}/check-in", $page);
        $this->assertStringContainsString("t('tournaments.public.check_in_now')", $page);
        $this->assertMatchesRegularExpression('/canRegister: boolean;\s*canCheckIn: boolean;/', $this->source('types/tournaments.ts'));
    }

    public function test_the_check_in_text_exists_in_both_locales(): void
    {
        foreach (['locales/es.ts', 'locales/en.ts'] as $locale) {
            $found = preg_match('/^ {8}public: \{\n(.*?)^ {8}\},$/ms', $this->source($locale), $block);
            $this->assertSame(1, $found, "{$locale} has the public block");
            $this->assertMatchesRegularExpression('/^ {12}check_in_now: /m', $block[1], $locale);
        }
    }

    private function source(string $file): string
    {
        $source = file_get_contents(self::JS . $file);
        $this->assertIsString($source);

        return $source;
    }
}
