<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Tournaments\Application\DTOs\MatchReportOutcome;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchNotFoundException;
use Modules\Tournaments\Domain\Exceptions\MatchReportClosedException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Exceptions\UnauthorizedToReportResultException;
use Modules\Tournaments\Http\Requests\ConfirmMatchResultRequest;
use Modules\Tournaments\Http\Requests\DisputeMatchResultRequest;
use Modules\Tournaments\Http\Requests\ReportMatchResultRequest;

/**
 * The players' side of a match result, from the tournament page (JSON).
 */
final class MatchReportController extends Controller
{
    public function __construct(
        private readonly MatchReportingServiceInterface $reporting,
    ) {}

    public function report(ReportMatchResultRequest $request, string $matchId): JsonResponse
    {
        return $this->respond(function () use ($request, $matchId): JsonResponse {
            $outcome = $this->reporting->report(new ReportMatchResultDTO(
                matchId: $matchId,
                result: MatchResult::from($request->string('result')->toString()),
                reportedById: (string) $request->user()?->getAuthIdentifier(),
                player1Score: $this->score($request->input('player_1_score')),
                player2Score: $this->score($request->input('player_2_score')),
                player1Stats: $this->stats($request->validated('player_1_stats')),
                player2Stats: $this->stats($request->validated('player_2_stats')),
            ));

            return response()->json([
                'message' => $outcome === MatchReportOutcome::Recorded
                    ? __('tournaments::messages.my_match.recorded')
                    : __('tournaments::messages.my_match.awaiting_confirmation'),
                'outcome' => $outcome->value,
            ]);
        });
    }

    public function confirm(ConfirmMatchResultRequest $request, string $matchId): JsonResponse
    {
        return $this->respond(function () use ($request, $matchId): JsonResponse {
            $this->reporting->confirm($matchId, (string) $request->user()?->getAuthIdentifier(), $request->string('report')->toString());

            return response()->json(['message' => __('tournaments::messages.my_match.confirmed')]);
        });
    }

    public function dispute(DisputeMatchResultRequest $request, string $matchId): JsonResponse
    {
        return $this->respond(function () use ($request, $matchId): JsonResponse {
            $this->reporting->dispute(
                $matchId,
                (string) $request->user()?->getAuthIdentifier(),
                trim($request->string('reason')->toString()),
                $request->string('report')->toString(),
            );

            return response()->json(['message' => __('tournaments::messages.my_match.disputed')]);
        });
    }

    /**
     * @param  callable(): JsonResponse  $action
     */
    private function respond(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (MatchNotFoundException) {
            abort(404);
        } catch (UnauthorizedToReportResultException) {
            return $this->refusal('not_allowed', 403);
        } catch (MatchReportClosedException) {
            return $this->refusal('closed', 409);
        } catch (ResultNotConfirmedException $exception) {
            return $this->refusal($exception->reportWasChanged() ? 'changed' : 'nothing_to_confirm', 409);
        } catch (InvalidMatchResultException $exception) {
            return $this->refusal($exception->reason() === InvalidMatchResultException::SCORES_CONTRADICT_RESULT ? 'scores_contradict_result' : 'invalid_result', 422);
        }
    }

    private function refusal(string $reason, int $status): JsonResponse
    {
        return response()->json(['message' => __("tournaments::messages.my_match.errors.{$reason}")], $status);
    }

    private function score(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stats(mixed $stats): ?array
    {
        if (! is_array($stats)) {
            return null;
        }

        $kept = array_filter($stats, static fn (mixed $value): bool => $value !== null);

        return $kept === [] ? null : $kept;
    }
}
