<?php

declare(strict_types=1);

namespace Modules\Tournaments\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Infrastructure\Services\GuestRegistrationExpirer;

final class ExpireGuestRegistrationsCommand extends Command
{
    protected $signature = 'tournaments:expire-guest-registrations';

    protected $description = 'Expire unconfirmed guest registrations and clear the fingerprints of past tournaments';

    public function handle(GuestRegistrationExpirer $expirer, Clock $clock): int
    {
        $counts = $expirer->run(CarbonImmutable::instance($clock->now()));

        $this->info((string) __('tournaments::messages.console.expire_guest_registrations', [
            'expired' => $counts['expired'],
            'cleared' => $counts['fingerprints_cleared'],
        ]));

        return self::SUCCESS;
    }
}
