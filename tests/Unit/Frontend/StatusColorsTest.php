<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Frontend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source guards, not behaviour tests: the module has no Vue test runner. Every status badge
 * gets its classes from the one shared map, keyed by the colors the server sends.
 */
final class StatusColorsTest extends TestCase
{
    private const string JS = __DIR__ . '/../../../resources/js/';

    /**
     * @return array<string, array{string, string}>
     */
    public static function serverColors(): array
    {
        return [
            'success' => ['success', 'bg-success-light text-base-primary'],
            'info' => ['info', 'bg-info-light text-base-primary'],
            'warning' => ['warning', 'bg-warning-light text-base-primary'],
            'danger' => ['danger', 'bg-error-light text-base-primary'],
            'gray' => ['gray', 'bg-base-secondary/10 text-base-secondary'],
            'primary' => ['primary', 'bg-primary-light text-base-primary'],
        ];
    }

    #[DataProvider('serverColors')]
    public function test_the_shared_map_has_every_server_color(string $key, string $classes): void
    {
        $this->assertStringContainsString("{$key}: '{$classes}'", $this->source('utils/statusColors.ts'));
    }

    public function test_an_unknown_color_falls_back_to_gray_without_touching_the_prototype(): void
    {
        $source = $this->source('utils/statusColors.ts');

        $this->assertStringContainsString('Object.hasOwn(', $source);
        $this->assertStringContainsString("'bg-base-secondary/10 text-base-secondary'", $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badgeFiles(): array
    {
        return [
            'listing card' => ['components/TournamentCard.vue'],
            'my tournaments card' => ['components/MyTournamentCard.vue'],
            'profile card' => ['components/profile/ProfileTournamentCard.vue'],
        ];
    }

    #[DataProvider('badgeFiles')]
    public function test_the_cards_use_the_shared_map_and_keep_no_local_copy(string $file): void
    {
        $source = $this->source($file);

        $this->assertStringContainsString("from '../utils/statusColors'", str_replace('../../utils', '../utils', $source));
        $this->assertStringContainsString('statusBadgeClasses(', $source);
        $this->assertStringNotContainsString('colorMap', $source);
        $this->assertStringNotContainsString("green: 'bg-", $source);
        $this->assertStringNotContainsString("amber:", $source);
    }

    public function test_the_event_section_chip_uses_the_shared_map_for_the_display_status(): void
    {
        $source = $this->source('components/EventTournamentSection.vue');

        $this->assertStringContainsString("from '../utils/statusColors'", $source);
        $this->assertStringContainsString("from '../composables/useTournaments'", $source);
        $this->assertStringContainsString('statusBadgeClasses(getStatusColor(tournament.display_status))', $source);
        $this->assertStringNotContainsString('bg-green-100', $source);
        $this->assertStringNotContainsString('bg-blue-100 text-blue-800', $source);
        $this->assertStringNotContainsString('bg-gray-100 text-gray-800', $source);
    }

    private function source(string $file): string
    {
        $source = file_get_contents(self::JS . $file);
        $this->assertIsString($source);

        return $source;
    }
}
