<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Unit\Infrastructure\Services;

use Modules\Tournaments\Domain\Enums\GuestAccountPolicy;
use Modules\Tournaments\Domain\Enums\GuestConfirmationMode;
use Modules\Tournaments\Infrastructure\Services\GuestRegistrationSettings;
use Tests\Support\Modules\ModuleTestCase;

final class GuestRegistrationSettingsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    public function test_defaults_without_panel_values(): void
    {
        config()->set('modules.settings.tournaments', []);

        $settings = new GuestRegistrationSettings;

        $this->assertSame(GuestConfirmationMode::Hold, $settings->confirmationMode());
        $this->assertSame(2, $settings->holdHours());
        $this->assertSame(2, $settings->maxUnconfirmedHeld());
        $this->assertSame(GuestAccountPolicy::RequireLogin, $settings->accountPolicy());
        $this->assertSame(5, $settings->perMinute());
        $this->assertSame(20, $settings->perHour());
        $this->assertSame(24, $settings->duplicateWindowHours());
    }

    public function test_panel_values_override(): void
    {
        config()->set('modules.settings.tournaments', [
            'default_result_reporting' => 'admin_only',
            'guest_confirmation' => ['mode' => 'wait', 'hold_hours' => '6', 'max_unconfirmed_held' => 0],
            'guest_accounts' => ['mode' => 'allow_guest'],
            'guest_rate_limit' => ['per_minute' => 10, 'per_hour' => 50],
            'duplicate_signal' => ['window_hours' => 48],
        ]);

        $settings = new GuestRegistrationSettings;

        $this->assertSame(GuestConfirmationMode::Wait, $settings->confirmationMode());
        $this->assertSame(6, $settings->holdHours());
        $this->assertSame(0, $settings->maxUnconfirmedHeld());
        $this->assertSame(GuestAccountPolicy::AllowGuest, $settings->accountPolicy());
        $this->assertSame(10, $settings->perMinute());
        $this->assertSame(50, $settings->perHour());
        $this->assertSame(48, $settings->duplicateWindowHours());
    }

    public function test_a_partial_panel_value_keeps_the_other_defaults(): void
    {
        config()->set('modules.settings.tournaments', ['guest_confirmation' => ['mode' => 'wait']]);

        $settings = new GuestRegistrationSettings;

        $this->assertSame(GuestConfirmationMode::Wait, $settings->confirmationMode());
        $this->assertSame(2, $settings->holdHours());
    }

    public function test_invalid_values_fall_back(): void
    {
        config()->set('modules.settings.tournaments', [
            'guest_confirmation' => ['mode' => 'nope', 'hold_hours' => 0, 'max_unconfirmed_held' => -1],
            'guest_accounts' => ['mode' => 'nope'],
            'guest_rate_limit' => ['per_minute' => '-3', 'per_hour' => 'abc'],
            'duplicate_signal' => ['window_hours' => 0],
        ]);

        $settings = new GuestRegistrationSettings;

        $this->assertSame(GuestConfirmationMode::Hold, $settings->confirmationMode());
        $this->assertSame(2, $settings->holdHours());
        $this->assertSame(2, $settings->maxUnconfirmedHeld());
        $this->assertSame(GuestAccountPolicy::RequireLogin, $settings->accountPolicy());
        $this->assertSame(5, $settings->perMinute());
        $this->assertSame(20, $settings->perHour());
        $this->assertSame(24, $settings->duplicateWindowHours());
    }
}
