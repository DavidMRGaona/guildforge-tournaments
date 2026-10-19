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
    private const string RESULT_KEY = 'guest_confirmation_outcome';

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
            'result' => $this->result(),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('tournaments::messages.seo.confirm_registration_title'),
                noindex: true,
            ))->toArray(),
        ]);
    }

    /**
     * Back to the confirmation page, which shows the outcome: the tournament page renders no flashes.
     */
    public function store(string $token): RedirectResponse
    {
        $result = $this->participantService->confirmGuestEmail($token);

        return redirect()
            ->route('tournaments.confirm-registration', $token)
            ->with(self::RESULT_KEY, $result->outcome->value);
    }

    /**
     * @return array{outcome: string, message: string}|null
     */
    private function result(): ?array
    {
        $outcome = GuestConfirmationOutcome::tryFrom((string) session(self::RESULT_KEY));

        return $outcome === null ? null : [
            'outcome' => $outcome->value,
            'message' => (string) __('tournaments::messages.guest_confirmation.'.$outcome->value),
        ];
    }
}
