<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The public tournament page lists participants by name: their emails never reach the browser.
 */
final class PublicParticipantsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_participants_list_carries_no_emails(): void
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addWeek()]);
        $tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo público',
            'slug' => 'torneo-publico',
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => true,
            'show_participants' => true,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
        ]);
        $user = UserModel::factory()->create(['name' => 'Berta', 'email' => 'berta@example.com']);
        ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'email_confirmed_at' => now()->subDay(),
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => now()->subDays(2),
        ]);
        ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => now()->subDay(),
        ]);

        $response = $this->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('participants', 2)
                ->has('participants.0.display_name')
                ->missing('participants.0.guest_email')
                ->missing('participants.0.user_email')
                ->missing('participants.1.guest_email')
                ->missing('participants.1.user_email'));

        $names = array_column($response->viewData('page')['props']['participants'], 'display_name');
        sort($names);
        $this->assertSame(['Ana', 'Berta'], $names);

        $response->assertDontSee('ana@example.com')
            ->assertDontSee('berta@example.com');
    }
}
