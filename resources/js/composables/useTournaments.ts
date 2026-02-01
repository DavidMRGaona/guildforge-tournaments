import { useI18n } from 'vue-i18n';
import type {
    Tournament,
    TournamentStatus,
    ParticipantStatus,
    RoundStatus,
    MatchResult,
    Standing,
} from '../types/tournaments';

interface StatusInfo {
    label: string;
    color: string;
}

interface UseTournamentsReturn {
    formatStatus: (status: TournamentStatus) => StatusInfo;
    formatParticipantStatus: (status: ParticipantStatus) => StatusInfo;
    formatRoundStatus: (status: RoundStatus) => StatusInfo;
    formatMatchResult: (result: MatchResult) => StatusInfo;
    canShowStandings: (tournament: Tournament) => boolean;
    canShowRounds: (tournament: Tournament) => boolean;
    hasStarted: (tournament: Tournament) => boolean;
    shouldShowParticipants: (tournament: Tournament) => boolean;
    getStatusColor: (status: TournamentStatus) => string;
    getParticipantStatusColor: (status: ParticipantStatus) => string;
    getRoundStatusColor: (status: RoundStatus) => string;
    getMatchResultColor: (result: MatchResult) => string;
    formatPoints: (points: number) => string;
    formatPercentage: (percentage: number) => string;
    formatRank: (rank: number) => string;
    formatRecord: (standing: Standing) => string;
    spotsRemaining: (tournament: Tournament) => number | null;
    isTournamentFull: (tournament: Tournament) => boolean;
}

export function useTournaments(): UseTournamentsReturn {
    const { t } = useI18n();

    function getStatusColor(status: TournamentStatus): string {
        switch (status) {
            case 'draft':
                return 'gray';
            case 'registration_open':
                return 'green';
            case 'registration_closed':
                return 'yellow';
            case 'in_progress':
                return 'blue';
            case 'finished':
                return 'purple';
            case 'cancelled':
                return 'red';
            default:
                return 'gray';
        }
    }

    function formatStatus(status: TournamentStatus): StatusInfo {
        return {
            label: t(`tournaments.status.${status}`),
            color: getStatusColor(status),
        };
    }

    function getParticipantStatusColor(status: ParticipantStatus): string {
        switch (status) {
            case 'registered':
                return 'yellow';
            case 'confirmed':
                return 'green';
            case 'checked_in':
                return 'blue';
            case 'withdrawn':
                return 'gray';
            case 'disqualified':
                return 'red';
            default:
                return 'gray';
        }
    }

    function formatParticipantStatus(status: ParticipantStatus): StatusInfo {
        return {
            label: t(`tournaments.participant_status.${status}`),
            color: getParticipantStatusColor(status),
        };
    }

    function getRoundStatusColor(status: RoundStatus): string {
        switch (status) {
            case 'pending':
                return 'gray';
            case 'in_progress':
                return 'blue';
            case 'finished':
                return 'green';
            default:
                return 'gray';
        }
    }

    function formatRoundStatus(status: RoundStatus): StatusInfo {
        return {
            label: t(`tournaments.round_status.${status}`),
            color: getRoundStatusColor(status),
        };
    }

    function getMatchResultColor(result: MatchResult): string {
        switch (result) {
            case 'pending':
                return 'gray';
            case 'player1_win':
            case 'player2_win':
                return 'green';
            case 'draw':
                return 'yellow';
            case 'double_loss':
                return 'red';
            case 'bye':
                return 'purple';
            default:
                return 'gray';
        }
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
