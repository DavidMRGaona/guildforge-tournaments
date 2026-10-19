<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Modules\Tournaments\Console\Commands\ExpireGuestRegistrationsCommand;
use Tests\Support\Modules\ModuleTestCase;

final class ExpireGuestRegistrationsCommandTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        // The module is booted after the console application exists, so its commands have to be
        // added to it by hand
        $this->app->make(Kernel::class)->registerCommand($this->app->make(ExpireGuestRegistrationsCommand::class));
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->artisan('tournaments:expire-guest-registrations')
            ->expectsOutput('Inscripciones caducadas: 0. Huellas borradas: 0.')
            ->assertExitCode(0);
    }

    public function test_the_command_is_scheduled_every_five_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'tournaments:expire-guest-registrations'));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);
        $this->assertSame(10, $events->first()->expiresAt);
    }
}
