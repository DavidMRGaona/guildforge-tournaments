<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The subtitle under a page title looks like the core's and the other modules': mt-2, text-lg and
 * the secondary text colour (the muted one is for hints, and «Mis torneos» had it smaller too).
 */
final class PageBandSubtitleTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'tournaments' => ['Index.vue', 'tournaments.listing.subtitle'],
            'my tournaments' => ['MyTournaments.vue', 'tournaments.my_tournaments.subtitle'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_band_subtitle_matches_the_rest_of_the_site(string $page, string $key): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/pages/Tournaments/'.$page);

        $this->assertMatchesRegularExpression(
            '/<p class="mt-2 text-lg text-base-secondary">\s*\{\{ t\(\''.preg_quote($key, '/').'\'\) \}\}/',
            $source,
        );
    }
}
