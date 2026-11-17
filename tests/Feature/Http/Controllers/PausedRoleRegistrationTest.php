<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use App\Infrastructure\TwoFactor\TwoFactorSessionSeal;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Authorization\ManagesTwoFactorRoles;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A tournament restricted to a role only lets in its active holders: a role the core pauses
 * («Requiere ser socio» without membership, «Requiere doble factor» without two-factor) gives
 * nothing, on the page, in the registration status and when registering.
 */
final class PausedRoleRegistrationTest extends ModuleTestCase
{
    use ManagesTwoFactorRoles;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private const string ROLE = 'liga';

    /**
     * @return array<string, array{string}>
     */
    public static function pauseReasons(): array
    {
        return [
            'membership' => ['membership'],
            'two-factor' => ['two_factor'],
        ];
    }

    #[DataProvider('pauseReasons')]
    public function test_a_paused_role_does_not_offer_registration(string $reason): void
    {
        $tournament = $this->tournament();
        $user = $this->holderOfPausedRole($reason);

        $this->actingAs($user)
            ->getJson(route('tournaments.registration.show', $tournament->id))
            ->assertOk()
            ->assertJsonPath('data.can_register', false);

        $this->actingAs($user)
            ->get(route('tournaments.show', $tournament->slug))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    }

    #[DataProvider('pauseReasons')]
    public function test_a_paused_role_cannot_register(string $reason): void
    {
        $tournament = $this->tournament();
        $user = $this->holderOfPausedRole($reason);

        $this->actingAs($user)
            ->postJson(route('tournaments.registration.store', $tournament->id))
            ->assertForbidden();

        $this->assertFalse(ParticipantModel::query()
            ->where('tournament_id', $tournament->id)
            ->where('user_id', $user->id)
            ->exists());
    }

    #[DataProvider('pauseReasons')]
    public function test_an_active_holder_can_register(string $reason): void
    {
        $tournament = $this->tournament();
        $user = $this->holderOfPausedRole($reason);
        $this->wakeUp($user, $reason);
        $user->refresh();
        // With two-factor on, a session that never passed it is signed out
        $session = [TwoFactorSessionSeal::SESSION_KEY => $user->session_seal];

        $this->actingAs($user)
            ->withSession($session)
            ->getJson(route('tournaments.registration.show', $tournament->id))
            ->assertOk()
            ->assertJsonPath('data.can_register', true);

        $this->actingAs($user)
            ->withSession($session)
            ->postJson(route('tournaments.registration.store', $tournament->id))
            ->assertCreated();
    }

    private function holderOfPausedRole(string $reason): UserModel
    {
        if ($reason === 'membership') {
            $role = RoleModel::query()->firstOrCreate(['name' => self::ROLE], ['display_name' => 'Liga']);
            $role->requires_association_member = true;
            $role->save();
        } else {
            $this->roleRequiringTwoFactor(self::ROLE);
        }

        return $this->accountHolding([self::ROLE]);
    }

    private function wakeUp(UserModel $user, string $reason): void
    {
        if ($reason === 'membership') {
            $socio = RoleModel::query()->firstOrCreate(['name' => 'socio'], ['display_name' => 'Socio']);
            $user->roles()->attach($socio->id);

            return;
        }

        $this->turnOnTwoFactor($user);
    }

    private function tournament(): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addDays(7)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de la liga',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationOpen,
            'allow_guests' => false,
            'allowed_roles' => [self::ROLE],
            'result_reporting' => 'admin_only',
            'notification_email' => 'avisos@example.com',
        ]);
    }
}
