<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Database;

use Modules\Tournaments\Database\Seeders\GameProfilesSeeder;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The seeder only adds missing profiles: running it again must not overwrite
 * what admins edited in the panel.
 */
final class GameProfilesSeederTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        // database/ is outside the module's autoloaded src/
        require_once dirname(__DIR__, 3).'/database/seeders/GameProfilesSeeder.php';
    }

    public function test_running_the_seeder_again_keeps_admin_edits(): void
    {
        $this->seed(GameProfilesSeeder::class);
        $count = GameProfileModel::query()->count();
        $profile = GameProfileModel::query()->firstOrFail();
        // Not the name: renaming regenerates the slug (HasSlug), which the seeder matches on
        $profile->update(['description' => 'Edited by an admin']);

        $this->seed(GameProfilesSeeder::class);

        $this->assertSame('Edited by an admin', $profile->fresh()?->description);
        $this->assertSame($count, GameProfileModel::query()->count());
    }
}
