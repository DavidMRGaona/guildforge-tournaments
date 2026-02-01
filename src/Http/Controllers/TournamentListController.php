<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Http\Concerns\BuildsPaginatedResponse;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Http\Resources\TournamentResource;

final class TournamentListController extends Controller
{
    use BuildsPaginatedResponse;

    private const PER_PAGE = 12;

    private const VALID_FILTERS = ['all', 'active', 'upcoming', 'past'];

    /**
     * Filter mappings for grouped status filters.
     *
     * @var array<string, array<string>>
     */
    private const FILTER_MAPPINGS = [
        'active' => [TournamentStatus::InProgress->value],
        'upcoming' => [
            TournamentStatus::RegistrationOpen->value,
            TournamentStatus::RegistrationClosed->value,
        ],
        'past' => [TournamentStatus::Finished->value],
    ];

    public function __construct(
        private readonly TournamentQueryServiceInterface $tournamentQuery,
    ) {}

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $statusParam = $request->query('status');
        $currentStatus = $this->parseStatusFilter($statusParam);
        $statusFilter = $this->mapStatusFilter($currentStatus);

        $tournaments = $this->tournamentQuery->getPublishedPaginated($page, self::PER_PAGE, $statusFilter);
        $total = $this->tournamentQuery->getPublishedTotal($statusFilter);

        return Inertia::render('Tournaments/Index', [
            'tournaments' => $this->buildPaginatedResponse(
                items: $tournaments,
                total: $total,
                page: $page,
                perPage: self::PER_PAGE,
                resourceClass: TournamentResource::class,
            ),
            'currentFilter' => $currentStatus ?? 'all',
        ]);
    }

    private function parseStatusFilter(mixed $statusParam): ?string
    {
        if (! is_string($statusParam) || $statusParam === '') {
            return null;
        }

        if (! in_array($statusParam, self::VALID_FILTERS, true)) {
            return null;
        }

        if ($statusParam === 'all') {
            return null;
        }

        return $statusParam;
    }

    /**
     * @return array<string>|null
     */
    private function mapStatusFilter(?string $currentStatus): ?array
    {
        if ($currentStatus === null) {
            return null;
        }

        return self::FILTER_MAPPINGS[$currentStatus] ?? null;
    }
}
