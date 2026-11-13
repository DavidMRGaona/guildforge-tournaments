<?php

declare(strict_types=1);

namespace Modules\Tournaments\Notifications;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Application\SiteNotifications\DTOs\SiteNotificationGroupDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Filament\Resources\TournamentResource;
use Modules\Tournaments\TournamentsServiceProvider;

/**
 * What the accounts that manage a tournament see in the bell, on top of the mail to the
 * tournament's address: a registration or a withdrawal (to whoever manages participants) and a
 * disputed result (to whoever records results). Bell only: the address already gets the mail.
 *
 * Whether the account still holds the permission, and where the notice leads, is decided when it
 * is delivered: the tab of the panel when the account can open the tournament there, its public
 * page otherwise, and nothing for a draft it cannot open.
 */
final class TournamentManagementNotification extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use Queueable;

    public const string REGISTERED = 'registered';

    public const string WITHDRAWN = 'withdrawn';

    public const string DISPUTED = 'disputed';

    public const string SUBJECT_TYPE = 'tournaments.tournament';

    private const string PANEL_PERMISSION = 'admin.access';

    private const string EDIT_PERMISSION = 'tournaments:tournaments.update';

    /** Position of each tab in TournamentResource::getRelations(), as the panel reads it from `?relation=`. */
    private const string PARTICIPANTS_TAB = '0';

    private const string MATCHES_TAB = '2';

    /**
     * @param  self::REGISTERED|self::WITHDRAWN|self::DISPUTED  $kind
     * @param  string|null  $body  A short line already written (the places taken, the disputed result).
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $tournamentId,
        private readonly string $tournamentName,
        private readonly string $actorName,
        private readonly ?string $actorId,
        private readonly ?string $body = null,
    ) {}

    /**
     * The permission that makes someone a recipient of this kind of notice.
     */
    public static function permissionFor(string $kind): string
    {
        return $kind === self::DISPUTED
            ? 'tournaments:tournaments.report_results'
            : 'tournaments:tournaments.manage_participants';
    }

    public function permission(): string
    {
        return self::permissionFor($this->kind);
    }

    public function mailCategory(): string
    {
        return TournamentsServiceProvider::MAIL_MANAGEMENT;
    }

    public function mailSubject(): MailSubjectDTO
    {
        return new MailSubjectDTO(self::SUBJECT_TYPE, $this->tournamentId, $this->tournamentName, 'tournaments::mail.subjects.tournament');
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [HasSiteNotification::CHANNEL];
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        if (! $notifiable instanceof UserModel) {
            return null;
        }

        $authorization = app(AuthorizationServiceInterface::class);

        if (! $authorization->can($notifiable, $this->permission())) {
            return null;
        }

        $tournament = app(TournamentQueryServiceInterface::class)->find($this->tournamentId);

        if (! $tournament instanceof TournamentResponseDTO) {
            return null;
        }

        $url = $this->pathFor($notifiable, $tournament, $authorization);

        if ($url === null) {
            return null;
        }

        $participants = $this->kind !== self::DISPUTED;

        return new SiteNotificationDTO(
            title: __('tournaments::messages.site.management.'.$this->kind, [
                'name' => $this->actorName,
                'tournament' => $this->tournamentName,
            ]),
            url: $url,
            body: $this->body,
            icon: $participants ? 'users' : 'swords',
            // Apart from the notices the same person gets as a player of the tournament
            groupKey: self::SUBJECT_TYPE.':'.$tournament->id.':'.($participants ? 'participants' : 'results'),
            actorId: $this->actorId,
            groupedTitle: fn (SiteNotificationGroupDTO $group): string => trans_choice(
                $participants ? 'tournaments::messages.site.management.participants_grouped' : 'tournaments::messages.site.management.disputes_grouped',
                $group->count,
                ['count' => $group->count, 'tournament' => $this->tournamentName],
            ),
        );
    }

    /**
     * Relative, so the bell never carries an address. Null when the account could open neither
     * the tournament in the panel nor its public page (a draft).
     */
    private function pathFor(UserModel $user, TournamentResponseDTO $tournament, AuthorizationServiceInterface $authorization): ?string
    {
        if ($authorization->can($user, self::PANEL_PERMISSION) && $authorization->can($user, self::EDIT_PERMISSION)) {
            return TournamentResource::getUrl('edit', [
                'record' => $tournament->id,
                'relation' => $this->kind === self::DISPUTED ? self::MATCHES_TAB : self::PARTICIPANTS_TAB,
            ], isAbsolute: false);
        }

        return $tournament->isDraft() ? null : route('tournaments.show', $tournament->slug, absolute: false);
    }
}
