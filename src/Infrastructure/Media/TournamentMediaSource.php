<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Media;

use App\Application\Media\Contracts\ReleasesMediaThroughCoreInterface;
use App\Application\Media\Contracts\ShowsMediaPreviews;
use App\Infrastructure\Media\Sources\EloquentColumnMediaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * The image of each tournament in the panel media library (core ≥ 3.53). Tournaments release replaced
 * images through the core (the model's DeletesCloudinaryImages), so the library may offer them to other records.
 *
 * Its previews show (core#837): a tournament's page is public as soon as it leaves its draft, cancelled ones
 * included. A draft is known to its organisers only, so its image and its name stay out of sight.
 */
final class TournamentMediaSource extends EloquentColumnMediaSource implements ReleasesMediaThroughCoreInterface, ShowsMediaPreviews
{
    public const string TYPE = 'tournaments.tournaments';

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return __('tournaments::messages.media.sources.tournaments');
    }

    public function folders(): array
    {
        return ['tournaments/'];
    }

    protected function modelClass(): string
    {
        return TournamentModel::class;
    }

    protected function column(): string
    {
        return 'image_public_id';
    }

    protected function role(): string
    {
        return __('tournaments::messages.media.roles.image');
    }

    protected function titleOf(Model $record): string
    {
        return $this->isHidden($record)
            ? (string) __('tournaments::messages.media.unpublished_tournament')
            : (string) $record->getAttribute('name');
    }

    protected function isHidden(Model $record): bool
    {
        // Anything that is not a tournament model past its draft stays hidden
        return ! $record instanceof TournamentModel || $record->status === TournamentStatus::Draft;
    }

    protected function editUrlOf(Model $record, object $user): ?string
    {
        if (! Gate::forUser($user)->allows('update', $record)) {
            return null;
        }

        return TournamentResource::getUrl('edit', ['record' => $record]);
    }
}
