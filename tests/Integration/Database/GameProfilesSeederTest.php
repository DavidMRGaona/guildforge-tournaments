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
        $this->seedProfiles();
        $count = GameProfileModel::query()->count();
        $profile = GameProfileModel::query()->firstOrFail();
        // Not the name: renaming regenerates the slug (HasSlug), which the seeder matches on
        $profile->update(['description' => 'Edited by an admin']);

        $this->seedProfiles();

        $this->assertSame('Edited by an admin', $profile->fresh()?->description);
        $this->assertSame($count, GameProfileModel::query()->count());
    }

    public function test_seeded_rules_are_in_the_shape_the_domain_reads(): void
    {
        $this->seedProfiles();

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
        $this->seedProfiles();
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

    public function test_every_system_profile_scores_a_bye_as_a_win(): void
    {
        $this->seedProfiles();

        foreach (GameProfileModel::query()->where('is_system', true)->get() as $profile) {
            $points = [];
            foreach ($profile->scoring_rules as $rule) {
                if (($rule['condition']['type'] ?? null) === 'result') {
                    $points[$rule['condition']['result_value']] = (float) $rule['points'];
                }
            }

            $this->assertArrayHasKey('bye', $points, $profile->slug);
            $this->assertSame($points['win'], $points['bye'], $profile->slug);
        }
    }

    public function test_the_bye_migration_updates_only_the_system_profiles(): void
    {
        $this->seedProfiles();
        $system = GameProfileModel::query()->where('slug', 'generic')->firstOrFail();
        $withoutBye = array_values(array_filter(
            $system->scoring_rules,
            static fn (array $rule): bool => ($rule['condition']['result_value'] ?? null) !== 'bye',
        ));
        DB::table('tournaments_game_profiles')->where('id', $system->id)->update(['scoring_rules' => json_encode($withoutBye)]);
        $custom = $system->replicate(['id', 'slug']);
        $custom->name = 'Perfil propio';
        $custom->is_system = false;
        $custom->forceFill(['scoring_rules' => $withoutBye])->save();
        $deleted = GameProfileModel::query()->where('slug', 'magic-the-gathering')->firstOrFail();
        DB::table('tournaments_game_profiles')->where('id', $deleted->id)->delete();

        (require dirname(__DIR__, 3).'/database/migrations/2026_10_14_000002_add_bye_rule_to_system_game_profiles.php')->up();

        $this->assertContains('bye', array_column(array_column($system->refresh()->scoring_rules, 'condition'), 'result_value'));
        $this->assertEquals($withoutBye, $custom->refresh()->scoring_rules);
        $this->assertFalse(GameProfileModel::query()->where('slug', 'magic-the-gathering')->exists());
    }

    private function seedProfiles(): void
    {
        $this->seed(GameProfilesSeeder::class);
    }
}
