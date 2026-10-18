<?php

declare(strict_types=1);

return [
    'default_result_reporting' => 'admin_only',
    'default_allow_guests' => false,
    'default_requires_check_in' => false,
    'default_check_in_starts_before' => 30,

    'guest_confirmation' => [
        // 'hold' keeps the guest's seat until the deadline, 'wait' holds nothing until they confirm
        'mode' => 'hold',
        // Hours a guest has to confirm their email (never past the registration closing)
        'hold_hours' => 2,
        // Unconfirmed guests holding a seat per tournament; further ones wait without a seat
        'max_unconfirmed_held' => 2,
    ],

    'guest_accounts' => [
        // 'require_login' asks the owner of a registered email to sign in, 'allow_guest' lets them register as a guest
        'mode' => 'require_login',
    ],

    'guest_rate_limit' => [
        // Guest registrations accepted per connection (IP) per minute
        'per_minute' => 5,
        // Guest registrations accepted per connection (IP) per hour
        'per_hour' => 20,
    ],

    'duplicate_signal' => [
        // Hours a registration counts as a possible duplicate of another from the same device
        'window_hours' => 24,
    ],
];
