<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Tournaments\Database\Seeders\GameProfilesSeeder;

/**
 * The system profiles gain a "Bye" rule worth a win. Only existing system profiles are
 * updated: admin-made profiles and their descriptions are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        // database/ is outside the module's autoloaded src/
        require_once dirname(__DIR__).'/seeders/GameProfilesSeeder.php';

        (new GameProfilesSeeder)->refreshSystemProfiles();
    }

    public function down(): void
    {
        // Nothing to undo: without the rule a bye already scores as a win
    }
};
