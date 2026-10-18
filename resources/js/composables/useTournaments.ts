import { useI18n } from 'vue-i18n';
import type {
    Tournament,
    TournamentDisplayStatus,
    ParticipantStatus,
    RoundStatus,
    MatchResult,
    Standing,
} from '../types/tournaments';
import { pickColor } from '../utils/statusColors';
import type { StatusColor } from '../utils/statusColors';

// Mirror TournamentStatus::color() / ParticipantStatus::color() / RoundStatus::color() / MatchResult::color() in PHP.
const TOURNAMENT_STATUS_COLORS: Record<TournamentDisplayStatus, StatusColor> = {
    draft: 'gray',
    registration_open: 'success',
    registration_upcoming: 'info',
    registration_closed: 'warning',
    in_progress: 'info',
    finished: 'primary',
    cancelled: 'danger',
};

const PARTICIPANT_STATUS_COLORS: Record<ParticipantStatus, StatusColor> = {
    registered: 'warning',
    confirmed: 'info',
    checked_in: 'success',
    withdrawn: 'gray',
    disqualified: 'danger',
};

const ROUND_STATUS_COLORS: Record<RoundStatus, StatusColor> = {
    pending: 'gray',
    in_progress: 'info',
    finished: 'success',
};

const MATCH_RESULT_COLORS: Record<MatchResult, StatusColor> = {
    player_one_win: 'success',
    player_two_win: 'success',
    draw: 'warning',
    double_loss: 'danger',
    bye: 'info',
    not_played: 'gray',
};

interface StatusInfo {
    label: string;
    color: StatusColor;
}

interface UseTournamentsReturn {
    formatStatus: (status: TournamentDisplayStatus) => StatusInfo;
    formatParticipantStatus: (status: ParticipantStatus) => StatusInfo;
    formatRoundStatus: (status: RoundStatus) => StatusInfo;
    formatMatchResult: (result: MatchResult) => StatusInfo;
    canShowStandings: (tournament: Tournament) => boolean;
    canShowRounds: (tournament: Tournament) => boolean;
    hasStarted: (tournament: Tournament) => boolean;
    shouldShowParticipants: (tournament: Tournament) => boolean;
    getStatusColor: (status: TournamentDisplayStatus) => StatusColor;
    getParticipantStatusColor: (status: ParticipantStatus) => StatusColor;
    getRoundStatusColor: (status: RoundStatus) => StatusColor;
    getMatchResultColor: (result: MatchResult) => StatusColor;
    formatPoints: (points: number) => string;
    formatPercentage: (percentage: number) => string;
    formatRank: (rank: number) => string;
    formatRecord: (standing: Standing) => string;
    spotsRemaining: (tournament: Tournament) => number | null;
    isTournamentFull: (tournament: Tournament) => boolean;
}

export function useTournaments(): UseTournamentsReturn {
    const { t } = useI18n();

    function getStatusColor(status: TournamentDisplayStatus): StatusColor {
        return pickColor(TOURNAMENT_STATUS_COLORS, status);
    }

    function formatStatus(status: TournamentDisplayStatus): StatusInfo {
        return {
            label: t(`tournaments.status.${status}`),
            color: getStatusColor(status),
        };
    }

    function getParticipantStatusColor(status: ParticipantStatus): StatusColor {
        return pickColor(PARTICIPANT_STATUS_COLORS, status);
    }

    function formatParticipantStatus(status: ParticipantStatus): StatusInfo {
        return {
            label: t(`tournaments.participant_status.${status}`),
            color: getParticipantStatusColor(status),
        };
    }

    function getRoundStatusColor(status: RoundStatus): StatusColor {
        return pickColor(ROUND_STATUS_COLORS, status);
    }

    function formatRoundStatus(status: RoundStatus): StatusInfo {
        return {
            label: t(`tournaments.round_status.${status}`),
            color: getRoundStatusColor(status),
        };
    }

    function getMatchResultColor(result: MatchResult): StatusColor {
        return pickColor(MATCH_RESULT_COLORS, result);
    }

    function formatMatchResult(result: MatchResult): StatusInfo {
        return {
            label: t(`tournaments.match_result.${result}`),
            color: getMatchResultColor(result),
        };
    }

    function hasStarted(tournament: Tournament): boolean {
        return tournament.status === 'in_progress' || tournament.status === 'finished';
    }

    function canShowStandings(tournament: Tournament): boolean {
        return hasStarted(tournament);
    }

    function canShowRounds(tournament: Tournament): boolean {
        return hasStarted(tournament);
    }

    function shouldShowParticipants(tournament: Tournament): boolean {
        return !hasStarted(tournament) && tournament.show_participants;
    }

    function formatPoints(points: number): string {
        return points.toFixed(1);
    }

    function formatPercentage(percentage: number): string {
        return `${percentage.toFixed(1)}%`;
    }

    function formatRank(rank: number): string {
        return `#${rank}`;
    }

    function formatRecord(standing: Standing): string {
        const parts = [
            `${standing.wins}${t('tournaments.record.win_short')}`,
            `${standing.draws}${t('tournaments.record.draw_short')}`,
            `${standing.losses}${t('tournaments.record.loss_short')}`,
        ];
        return parts.join(' - ');
    }

    function spotsRemaining(tournament: Tournament): number | null {
        if (tournament.max_participants === null) {
            return null;
        }
        return Math.max(0, tournament.max_participants - tournament.participant_count);
    }

    function isTournamentFull(tournament: Tournament): boolean {
        if (tournament.max_participants === null) {
            return false;
        }
        return tournament.participant_count >= tournament.max_participants;
    }

    return {
        formatStatus,
        formatParticipantStatus,
        formatRoundStatus,
        formatMatchResult,
        canShowStandings,
        canShowRounds,
        hasStarted,
        shouldShowParticipants,
        getStatusColor,
        getParticipantStatusColor,
        getRoundStatusColor,
        getMatchResultColor,
        formatPoints,
        formatPercentage,
        formatRank,
        formatRecord,
        spotsRemaining,
        isTournamentFull,
    };
}
