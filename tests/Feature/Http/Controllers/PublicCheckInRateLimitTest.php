<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Http\Controllers;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use Illuminate\Support\Str;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The public check-in is limited per IP (10 a minute, 60 an hour) so emails cannot be tried in bulk.
 */
final class PublicCheckInRateLimitTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    public function test_the_eleventh_check_in_attempt_in_a_minute_is_limited(): void
    {
        $tournament = $this->tournament();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson($this->checkInUrl($tournament), ['email' => "nadie{$attempt}@example.com", 'gdpr_consent' => true])
                ->assertStatus(422);
        }

        $this->postJson($this->checkInUrl($tournament), ['email' => 'nadie11@example.com', 'gdpr_consent' => true])
            ->assertStatus(429)
            ->assertJson(['message' => 'Has hecho demasiados intentos de check-in. Espera un momento y vuelve a probar.']);
    }

    public function test_an_inertia_check_in_over_the_limit_goes_back_with_the_error(): void
    {
        $tournament = $this->tournament();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->checkInByEmail($tournament, "nadie{$attempt}@example.com");
        }

        $this->from(route('tournaments.check-in.show', $tournament->slug))
            ->withHeaders(['X-Inertia' => 'true'])
            ->post($this->checkInUrl($tournament), ['email' => 'nadie11@example.com', 'gdpr_consent' => true])
            ->assertRedirect(route('tournaments.check-in.show', $tournament->slug))
            ->assertSessionHasErrors(['check_in' => 'Has hecho demasiados intentos de check-in. Espera un momento y vuelve a probar.']);
    }

    public function test_the_minute_limit_resets_while_the_hour_limit_keeps_counting(): void
    {
        $tournament = $this->tournament();

        // Six full minutes: each one resets the minute counter (separate keys), the hour adds up to 60
        for ($minute = 1; $minute <= 6; $minute++) {
            for ($attempt = 1; $attempt <= 10; $attempt++) {
                $this->postJson($this->checkInUrl($tournament), ['email' => "nadie{$minute}-{$attempt}@example.com", 'gdpr_consent' => true])
                    ->assertStatus(422);
            }

            $this->travel(61)->seconds();
        }

        $this->postJson($this->checkInUrl($tournament), ['email' => 'nadie@example.com', 'gdpr_consent' => true])
            ->assertStatus(429);
    }

    private function checkInByEmail(TournamentModel $tournament, string $email): void
    {
        $this->post($this->checkInUrl($tournament), ['email' => $email, 'gdpr_consent' => true]);
    }

    private function checkInUrl(TournamentModel $tournament): string
    {
        return route('tournaments.check-in.store', ['slug' => $tournament->slug]);
    }

    private function tournament(): TournamentModel
    {
        $event = EventModel::factory()->published()->create(['start_date' => now()->addMinutes(10)]);

        return TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Torneo de check-in',
            'slug' => 'torneo-'.Str::lower(Str::random(10)),
            'status' => TournamentStatus::RegistrationClosed,
            'result_reporting' => 'admin_only',
            'notification_email' => 'test@example.com',
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
        ]);
    }
}
