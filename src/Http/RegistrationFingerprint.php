<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http;

use Illuminate\Http\Request;

/**
 * The device signal stored on web registrations: IP and user agent, hashed with a key from the
 * application configuration, so the panel can flag likely duplicates without keeping either.
 */
final class RegistrationFingerprint
{
    public static function forRequest(Request $request): string
    {
        return hash_hmac(
            'sha256',
            ($request->ip() ?? '').'|'.($request->userAgent() ?? ''),
            (string) config('app.key'),
        );
    }
}
