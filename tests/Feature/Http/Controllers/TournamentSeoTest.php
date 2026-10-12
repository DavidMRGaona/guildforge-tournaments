<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Storage;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

final class TournamentSeoTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('es');
        config([
            'app.name' => 'Tolerol',
            'cloudinary.cloud_name' => 'demo',
            'cloudinary.prefix' => 'guildforge',
        ]);
        Storage::fake('images');
    }

    private function tournament(?string $image, ?string $eventImage = null): TournamentModel
    {
        $event = EventModel::factory()->published()->create([
            'title' => 'Jornadas',
            'start_date' => '2026-03-14 10:00:00',
            'end_date' => '2026-03-14 20:00:00',
            'location' => 'Centro cívico',
            'image_public_id' => $eventImage,
        ]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de Warhammer',
            'slug' => 'torneo-warhammer',
            'description' => '<p>Ven a competir</p>',
            'image_public_id' => $image,
            'status' => 'registration_open',
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'registration_opens_at' => now()->subWeek(),
        ]);
    }

    public function test_show_page_has_the_event_schedule_and_its_own_image(): void
    {
        $this->tournament('tournaments/warhammer.jpg', 'events/jornadas.jpg');

        $response = $this->get('/torneos/torneo-warhammer');

        $response->assertOk();
        $response->assertSee('<title>Torneo de Warhammer - Tolerol</title>', false);
        $response->assertSee('content="14 de marzo de 2026, 10:00 · Centro cívico · Ven a competir"', false);
        $response->assertSee('/guildforge/tournaments/warhammer" data-seo-server>', false);
        $response->assertSee('"@type":"Event"', false);
    }

    public function test_show_page_falls_back_to_the_event_image(): void
    {
        $this->tournament(null, 'events/jornadas.jpg');

        $this->get('/torneos/torneo-warhammer')
            ->assertSee('/guildforge/events/jornadas" data-seo-server>', false);
    }

    public function test_standings_and_rounds_have_their_own_titles(): void
    {
        $this->tournament(null);

        $this->get('/torneos/torneo-warhammer/clasificacion')
            ->assertSee('<title>Clasificación - Torneo de Warhammer - Tolerol</title>', false);
        $this->get('/torneos/torneo-warhammer/rondas')
            ->assertSee('<title>Rondas - Torneo de Warhammer - Tolerol</title>', false);
    }

    public function test_listing_has_its_title(): void
    {
        $this->get('/torneos')->assertSee('<title>Torneos - Tolerol</title>', false);
    }

    public function test_listing_page_component_exists_where_the_manifest_lookup_expects_it(): void
    {
        $this->assertFileExists(dirname(__DIR__, 4).'/resources/js/pages/Tournaments/Index.vue');
    }

    public function test_my_tournaments_is_noindex(): void
    {
        $this->actingAs(UserModel::factory()->create())
            ->get('/torneos/mis-torneos')
            ->assertSee('<meta name="robots" content="noindex, nofollow" data-seo-server>', false);
    }
}
