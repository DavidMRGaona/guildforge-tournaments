<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Media;

use App\Infrastructure\Media\Sources\EloquentColumnMediaSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

/**
 * The image of each tournament in the panel media library (core ≥ 3.53).
 */
final class TournamentMediaSource extends EloquentColumnMediaSource
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
        return (string) $record->getAttribute('name');
    }

    protected function editUrlOf(Model $record, object $user): ?string
    {
        if (! Gate::forUser($user)->allows('update', $record)) {
            return null;
        }

        return TournamentResource::getUrl('edit', ['record' => $record]);
    }
}
