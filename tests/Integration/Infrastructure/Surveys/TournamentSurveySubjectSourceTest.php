<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Surveys;

use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\PairingMethod;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\GameProfileModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Surveys\TournamentSurveySubjectSource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * A tournament as something a survey can be about: its snapshot, who is asked to answer (whoever
 * played a round) and who manages its surveys (its organizers and the tournament editors).
 */
final class TournamentSurveySubjectSourceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentSurveySubjectSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->source = $this->app->make(TournamentSurveySubjectSource::class);
    }

    public function test_the_registry_offers_tournaments(): void
    {
        $source = $this->app->make(SurveySubjectSourceRegistryInterface::class)->source('tournament');

        $this->assertInstanceOf(TournamentSurveySubjectSource::class, $source);
        $this->assertSame('tournament', $source->sourceType());
        $this->assertSame(__('tournaments::messages.surveys.source_label'), $source->sourceLabel());
    }

    public function test_find_returns_the_tournament_snapshot(): void
    {
        $organizer = UserModel::factory()->create();
        $profile = GameProfileModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'Juego de ejemplo',
            'slug' => 'juego-de-ejemplo-'.Str::random(6),
            'stat_definitions' => [],
            'scoring_rules' => [],
            'tiebreaker_config' => [],
            'pairing_config' => ['method' => PairingMethod::Random->value],
        ]);
        $tournament = $this->tournament(TournamentStatus::Finished, [
            'game_profile_id' => $profile->id,
            'started_at' => '2030-01-05 10:00:00',
            'completed_at' => '2030-01-05 19:30:00',
        ]);
        $tournament->organizers()->attach($organizer->id);

        $subject = $this->source->find($tournament->id);

        $this->assertInstanceOf(SurveySubjectDTO::class, $subject);
        $this->assertSame('tournament', $subject->sourceType);
        $this->assertSame($tournament->id, $subject->subjectId);
        $this->assertSame('Torneo de ejemplo', $subject->title);
        $this->assertEquals(new DateTimeImmutable('2030-01-05T10:00:00+00:00'), $subject->startsAt);
        $this->assertEquals(new DateTimeImmutable('2030-01-05T19:30:00+00:00'), $subject->endsAt);
        $this->assertSame([$organizer->id], $subject->organizerIds);
        $this->assertSame(route('tournaments.show', $tournament->slug), $subject->url);
        $this->assertSame(SurveySubjectStatus::Ended, $subject->status);

        $attributes = [];
        foreach ($subject->attributes as $attribute) {
            $attributes[$attribute->key] = [$attribute->label, $attribute->value, $attribute->valueId];
        }
        $this->assertSame([
            'game_system' => [__('tournaments::messages.surveys.attributes.game_system'), 'Juego de ejemplo', $profile->id],
            'tournament_format' => [__('tournaments::messages.surveys.attributes.tournament_format'), PairingMethod::Random->label(), null],
        ], $attributes);
    }

    public function test_before_it_starts_the_snapshot_uses_the_event_start_and_no_end(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen, [], '2030-02-01 09:00:00');

        $subject = $this->source->find($tournament->id);

        $this->assertInstanceOf(SurveySubjectDTO::class, $subject);
        $this->assertEquals(new DateTimeImmutable('2030-02-01T09:00:00+00:00'), $subject->startsAt);
        $this->assertNull($subject->endsAt);
        $this->assertSame([], $subject->organizerIds);
        $this->assertSame(SurveySubjectStatus::Active, $subject->status);

        $attributes = [];
        foreach ($subject->attributes as $attribute) {
            $attributes[$attribute->key] = $attribute->value;
        }
        // Without a game profile: no game, and the default pairing
        $this->assertSame(['game_system' => null, 'tournament_format' => PairingMethod::Swiss->label()], $attributes);
    }

    public function test_find_reports_a_cancelled_tournament(): void
    {
        $tournament = $this->tournament(TournamentStatus::Cancelled);

        $this->assertSame(SurveySubjectStatus::Cancelled, $this->source->find($tournament->id)?->status);
    }

    public function test_find_ignores_unknown_and_malformed_ids(): void
    {
        $this->assertNull($this->source->find((string) Str::uuid()));
        $this->assertNull($this->source->find('not-a-uuid'));
        $this->assertSame(0, $this->source->audience('not-a-uuid')->count());
        $this->assertSame(0, $this->source->audience((string) Str::uuid())->count());
        $this->assertFalse($this->source->canManage(UserModel::factory()->admin()->create()->id, (string) Str::uuid()));
    }

    public function test_audience_lists_who_played_a_round_whatever_their_final_status(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);
        $confirmed = $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::CheckedIn);
        $withdrawn = $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::Withdrawn);
        $disqualified = $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::Disqualified);
        $bye = $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::CheckedIn);
        $round = $this->round($tournament, 1);
        $this->pair($round, $confirmed, $withdrawn);
        $this->pair($this->round($tournament, 2), $disqualified, $confirmed);
        $this->pair($round, $bye, null);

        $audience = $this->source->audience($tournament->id);

        $this->assertEqualsCanonicalizing(
            [$confirmed->user_id, $withdrawn->user_id, $disqualified->user_id, $bye->user_id],
            $audience->userIds,
        );
        $this->assertSame([], $audience->guests);
    }

    public function test_audience_leaves_out_who_never_played(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);
        $played = $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::CheckedIn);
        $opponent = $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::CheckedIn);
        $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::Confirmed);
        $this->pair($this->round($tournament, 1), $played, $opponent);

        // Another tournament's match does not count here
        $other = $this->tournament(TournamentStatus::Finished);
        $elsewhere = $this->participant($other, UserModel::factory()->create(), ParticipantStatus::CheckedIn);
        $this->pair($this->round($other, 1), $elsewhere, null);

        $this->assertEqualsCanonicalizing([$played->user_id, $opponent->user_id], $this->source->audience($tournament->id)->userIds);
    }

    public function test_audience_leaves_out_anonymized_accounts(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);
        $player = $this->participant($tournament, UserModel::factory()->create(), ParticipantStatus::CheckedIn);
        $anonymized = UserModel::factory()->create();
        $anonymized->forceFill(['anonymized_at' => now()])->save();
        $gone = $this->participant($tournament, $anonymized, ParticipantStatus::CheckedIn);
        $this->pair($this->round($tournament, 1), $player, $gone);

        $this->assertSame([$player->user_id], $this->source->audience($tournament->id)->userIds);
    }

    public function test_audience_lists_guests_with_a_confirmed_valid_email_only(): void
    {
        $tournament = $this->tournament(TournamentStatus::Finished);
        $confirmed = $this->guest($tournament, 'Persona Invitada', 'invitada@example.com', confirmed: true);
        $unconfirmed = $this->guest($tournament, 'Sin confirmar', 'sinconfirmar@example.com', confirmed: false);
        $invalid = $this->guest($tournament, 'Correo roto', 'no-es-un-correo', confirmed: true);
        $nameless = $this->guest($tournament, null, 'sinnombre@example.com', confirmed: true);
        $round = $this->round($tournament, 1);
        $this->pair($round, $confirmed, $unconfirmed);
        $this->pair($round, $invalid, $nameless);

        $audience = $this->source->audience($tournament->id);

        $this->assertSame([], $audience->userIds);
        $guests = array_map(static fn (SurveyGuestDTO $guest): array => [$guest->reference, $guest->displayName, $guest->email], $audience->guests);
        $this->assertEqualsCanonicalizing([
            [$confirmed->id, 'Persona Invitada', 'invitada@example.com'],
            [$nameless->id, 'sinnombre@example.com', 'sinnombre@example.com'],
        ], $guests);
    }

    public function test_organizers_and_tournament_editors_can_manage(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);
        $organizer = UserModel::factory()->create();
        $tournament->organizers()->attach($organizer->id);
        $otherOrganizer = UserModel::factory()->create();
        $this->tournament(TournamentStatus::InProgress)->organizers()->attach($otherOrganizer->id);

        $this->assertTrue($this->source->canManage($organizer->id, $tournament->id));
        $this->assertTrue($this->source->canManage($this->userWith('tournaments:tournaments.update')->id, $tournament->id));
        $this->assertTrue($this->source->canManage(UserModel::factory()->admin()->create()->id, $tournament->id));

        $this->assertFalse($this->source->canManage($otherOrganizer->id, $tournament->id));
        $this->assertFalse($this->source->canManage($this->userWith('tournaments:tournaments.view')->id, $tournament->id));
        $this->assertFalse($this->source->canManage(UserModel::factory()->create()->id, $tournament->id));
        $this->assertFalse($this->source->canManage((string) Str::uuid(), $tournament->id));
    }

    public function test_it_lists_every_game_profile_by_id_for_the_survey_settings(): void
    {
        $profile = GameProfileModel::create([
            'id' => (string) Str::uuid(),
            'name' => 'Juego de ejemplo',
            'slug' => 'juego-de-ejemplo-'.Str::random(6),
            'stat_definitions' => [],
            'scoring_rules' => [],
            'tiebreaker_config' => [],
            'pairing_config' => [],
        ]);

        $labels = [];
        foreach ($this->source->attributeValues('game_system') as $value) {
            $labels[$value->id] = $value->label;
        }

        $expected = GameProfileModel::query()->pluck('name', 'id')->all();
        uasort($expected, strnatcasecmp(...));
        $this->assertSame($expected, $labels);
        $this->assertSame('Juego de ejemplo', $labels[$profile->id] ?? null);
        $this->assertSame([], $this->source->attributeValues('tournament_format'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(TournamentStatus $status, array $attributes = [], string $eventStartsAt = '2030-01-05 09:00:00'): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['start_date' => $eventStartsAt])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => $status->value,
            'result_reporting' => 'admin_only',
            ...$attributes,
        ]);
    }

    private function participant(TournamentModel $tournament, UserModel $user, ParticipantStatus $status): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'status' => $status->value,
            'registered_at' => now(),
        ]);
    }

    private function guest(TournamentModel $tournament, ?string $name, string $email, bool $confirmed): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'guest_name' => $name,
            'guest_email' => $email,
            'status' => ParticipantStatus::CheckedIn->value,
            'registered_at' => now(),
            'email_confirmed_at' => $confirmed ? now() : null,
        ]);
    }

    private function round(TournamentModel $tournament, int $number): RoundModel
    {
        return RoundModel::create([
            'id' => (string) Str::uuid(),
            'tournament_id' => $tournament->id,
            'round_number' => $number,
            'status' => RoundStatus::Finished->value,
        ]);
    }

    private function pair(RoundModel $round, ParticipantModel $first, ?ParticipantModel $second): void
    {
        MatchModel::create([
            'id' => (string) Str::uuid(),
            'round_id' => $round->id,
            'player_1_id' => $first->id,
            'player_2_id' => $second?->id,
        ]);
    }

    private function userWith(string $permissionKey): UserModel
    {
        [$module, $rest] = explode(':', $permissionKey);
        [$resource, $action] = explode('.', $rest);
        $role = RoleModel::create(['name' => 'role-'.Str::random(6), 'display_name' => 'Rol de ejemplo']);
        $permission = PermissionModel::firstOrCreate(
            ['key' => $permissionKey],
            ['label' => $permissionKey, 'resource' => $resource, 'action' => $action, 'module' => $module],
        );
        $role->permissions()->attach($permission->id);

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
