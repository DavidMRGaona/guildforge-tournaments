<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Tournaments\Database\Seeders\GameProfilesSeeder;

/**
 * Seeders run once per installation: run the game profiles seeder again so the system
 * profiles it created before get the rules in the shape the tournament form reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        // database/ is outside the module's autoloaded src/
        require_once dirname(__DIR__).'/seeders/GameProfilesSeeder.php';

        (new GameProfilesSeeder)->run();
    }

    public function down(): void
    {
        // Nothing to undo: 2.0.0 reads the same shape (its forms write it)
    }
};
