<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Database;

use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Database\Seeders\GameProfilesSeeder;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The seeder adds missing profiles and keeps the rules of system profiles current:
 * running it again must not overwrite what admins can edit in the panel.
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

    public function test_seeded_rules_are_in_the_shape_the_domain_reads(): void
    {
        $this->seed(GameProfilesSeeder::class);

        foreach (GameProfileModel::query()->get() as $profile) {
            foreach ($profile->scoring_rules as $rule) {
                ScoringRule::fromArray($rule);
            }
            foreach ($profile->tiebreaker_config as $tiebreaker) {
                TiebreakerDefinition::fromArray($tiebreaker);
            }
        }

        $this->assertSame(4, GameProfileModel::query()->where('is_system', true)->count());
    }

    public function test_the_migration_brings_profiles_seeded_before_up_to_date(): void
    {
        $this->seed(GameProfilesSeeder::class);
        $profile = GameProfileModel::query()->where('slug', 'warhammer-40k')->firstOrFail();
        // What an installation seeded with 2.0.0 or earlier holds
        DB::table('tournaments_game_profiles')->where('id', $profile->id)->update([
            'description' => 'Edited by an admin',
            'scoring_rules' => json_encode([['result' => 'win', 'points' => 3.0]]),
            'tiebreaker_config' => json_encode([['type' => 'buchholz', 'priority' => 1]]),
        ]);

        (require dirname(__DIR__, 3).'/database/migrations/2026_10_12_000001_reseed_system_game_profiles.php')->up();

        $profile->refresh();
        $this->assertSame($profile->id, GameProfileModel::query()->where('slug', 'warhammer-40k')->value('id'));
        $this->assertSame('Edited by an admin', $profile->description);
        $this->assertSame('result', $profile->scoring_rules[0]['condition']['type'] ?? null);
        $this->assertSame('buchholz', $profile->tiebreaker_config[0]['key'] ?? null);
    }

    public function test_the_legacy_game_profile_seeder_is_gone(): void
    {
        // Its nine profiles were never seeded: only database/seeders runs on enable
        $this->assertFalse(class_exists('Modules\\Tournaments\\Database\\Seeders\\GameProfileSeeder'));
    }
}
