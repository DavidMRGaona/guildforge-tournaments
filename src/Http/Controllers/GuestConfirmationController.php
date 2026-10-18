<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Application\DTOs\Response\GuestConfirmationOutcome;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;

/**
 * The link of the guest confirmation email. Opening it only shows the page; the button (POST)
 * confirms, so the link scanners of mail providers never confirm a registration on their own.
 */
final class GuestConfirmationController extends Controller
{
    public function __construct(
        private readonly ParticipantManagementServiceInterface $participantService,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function show(string $token): Response
    {
        $confirmation = $this->participantService->findGuestConfirmation($token) ?? [
            'state' => 'invalid',
            'tournament_name' => null,
            'tournament_slug' => null,
            'guest_name' => null,
            'expires_at' => null,
            'seat_held' => false,
        ];

        return Inertia::render('Tournaments/ConfirmRegistration', [
            'confirmation' => $confirmation,
            'token' => $token,
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('tournaments::messages.seo.confirm_registration_title'),
                noindex: true,
            ))->toArray(),
        ]);
    }

    public function store(string $token): RedirectResponse
    {
        $result = $this->participantService->confirmGuestEmail($token);

        $redirect = $result->tournamentSlug === null
            ? redirect()->route('tournaments.index')
            : redirect()->route('tournaments.show', $result->tournamentSlug);

        return match ($result->outcome) {
            GuestConfirmationOutcome::Confirmed => $redirect->with('success', __('tournaments::messages.guest_confirmation.confirmed')),
            GuestConfirmationOutcome::AlreadyConfirmed => $redirect->with('success', __('tournaments::messages.guest_confirmation.already_confirmed')),
            GuestConfirmationOutcome::Invalid => $redirect->with('error', __('tournaments::messages.guest_confirmation.invalid')),
            GuestConfirmationOutcome::RegistrationClosed => $redirect->with('error', __('tournaments::messages.guest_confirmation.registration_closed')),
            GuestConfirmationOutcome::Full => $redirect->with('error', __('tournaments::messages.guest_confirmation.full')),
        };
    }
}
