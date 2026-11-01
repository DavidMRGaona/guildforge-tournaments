<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Modules\ModuleTestCase;

final class MatchResultReportsMigrationTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_migration_rolls_back_and_restores_is_disputed(): void
    {
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_01_000001_add_match_result_reports.php';
        $this->assertInstanceOf(Migration::class, $migration);
        if (! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
            $this->fail('The migration must be reversible.');
        }

        $this->assertTrue(Schema::hasTable('tournaments_match_reports'));
        $this->assertTrue(Schema::hasColumn('tournaments_match_history', 'action'));
        $this->assertFalse(Schema::hasColumn('tournaments_matches', 'is_disputed'));

        $migration->down();

        $this->assertFalse(Schema::hasTable('tournaments_match_reports'));
        $this->assertFalse(Schema::hasColumn('tournaments_match_history', 'action'));
        $this->assertTrue(Schema::hasColumn('tournaments_matches', 'is_disputed'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('tournaments_match_reports'));
        $this->assertTrue(Schema::hasColumn('tournaments_match_history', 'action'));
        $this->assertFalse(Schema::hasColumn('tournaments_matches', 'is_disputed'));
    }
}
