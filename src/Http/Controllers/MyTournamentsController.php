<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Infrastructure\Services\ProfileTournamentsDataProvider;

final class MyTournamentsController extends Controller
{
    public function __construct(
        private readonly ProfileTournamentsDataProvider $dataProvider,
    ) {}

    public function __invoke(): Response|RedirectResponse
    {
        $user = auth()->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $data = $this->dataProvider->getDataForUser((string) $user->id);

        return Inertia::render('Tournaments/MyTournaments', [
            'upcoming' => $data['upcoming'] ?? [],
            'inProgress' => $data['inProgress'] ?? [],
            'past' => $data['past'] ?? [],
        ]);
    }
}
