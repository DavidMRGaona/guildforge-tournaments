export type TournamentStatus =
    | 'draft'
    | 'registration_open'
    | 'registration_closed'
    | 'in_progress'
    | 'finished'
    | 'cancelled';

export type ParticipantStatus = 'registered' | 'confirmed' | 'checked_in' | 'withdrawn' | 'disqualified';

export type RoundStatus = 'pending' | 'in_progress' | 'finished';

export type MatchResult =
    | 'pending'
    | 'player1_win'
    | 'player2_win'
    | 'draw'
    | 'double_loss'
    | 'bye';

export type ResultReporting = 'admin_only' | 'players_with_confirmation' | 'players_trusted';

export interface ScoreWeight {
    name: string;
    key: string;
    points: number;
}

export interface Tournament {
    id: string;
    event_id: string;
    name: string;
    slug: string;
    description: string | null;
    image_public_id: string | null;
    status: TournamentStatus;
    status_label: string;
    max_rounds: number | null;
    current_round: number;
    max_participants: number | null;
    min_participants: number | null;
    participant_count: number;
    score_weights: ScoreWeight[];
    tiebreakers: string[];
    allow_guests: boolean;
    allowed_roles: string[];
    result_reporting: ResultReporting;
    requires_check_in: boolean;
    check_in_starts_before: number | null;
    registration_opens_at: string | null;
    registration_closes_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string;
    updated_at: string;
    is_registration_open: boolean;
    is_in_progress: boolean;
    is_finished: boolean;
    has_capacity: boolean;
    recommended_rounds: number;
    show_participants: boolean;
}

export interface Participant {
    id: string;
    tournament_id: string;
    user_id: string | null;
    user_name: string | null;
    user_email: string | null;
    guest_name: string | null;
    guest_email: string | null;
    status: ParticipantStatus;
    status_label: string;
    seed: number | null;
    has_received_bye: boolean;
    registered_at: string;
    checked_in_at: string | null;
    display_name: string;
    is_guest: boolean;
    is_checked_in: boolean;
}

export interface Standing {
    tournament_id: string;
    participant_id: string;
    participant_name: string;
    rank: number;
    matches_played: number;
    wins: number;
    draws: number;
    losses: number;
    byes: number;
    points: number;
    buchholz: number;
    median_buchholz: number;
    progressive: number;
    opponent_win_percentage: number;
    win_percentage: number;
    draw_percentage: number;
    loss_percentage: number;
}

export interface Round {
    id: string;
    tournament_id: string;
    round_number: number;
    status: RoundStatus;
    status_label: string;
    match_count: number;
    completed_match_count: number;
    pending_match_count: number;
    completion_percentage: number;
    is_completed: boolean;
    started_at: string | null;
    completed_at: string | null;
}

export interface Match {
    id: string;
    round_id: string;
    player_1_id: string;
    player_1_name: string;
    player_2_id: string | null;
    player_2_name: string | null;
    table_number: number | null;
    result: MatchResult;
    result_label: string;
    player_1_score: number | null;
    player_2_score: number | null;
    reported_by_id: string | null;
    reported_by_name: string | null;
    reported_at: string | null;
    confirmed_by_id: string | null;
    confirmed_by_name: string | null;
    confirmed_at: string | null;
    is_disputed: boolean;
    is_bye: boolean;
    is_reported: boolean;
    is_confirmed: boolean;
    needs_confirmation: boolean;
}

export interface RoundWithMatches {
    round: Round;
    matches: Match[];
}

/**
 * API response for registration status.
 */
export interface RegistrationStatusResponse {
    data: {
        registration: Participant | null;
        can_register: boolean;
    };
}

/**
 * API response for successful registration.
 */
export interface RegistrationResponse {
    message: string;
    data: Participant;
}

/**
 * API error response.
 */
export interface ApiErrorResponse {
    message: string;
}

/**
 * Tournament listing item (camelCase for Inertia pages).
 */
export interface TournamentListItem {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    imagePublicId: string | null;
    status: TournamentStatus;
    statusLabel: string;
    statusColor: string;
    currentRound: number | null;
    maxRounds: number | null;
    participantCount: number;
    maxParticipants: number | null;
    minParticipants: number | null;
    registrationOpensAt: string | null;
    registrationClosesAt: string | null;
    startedAt: string | null;
    completedAt: string | null;
    isRegistrationOpen: boolean;
    isInProgress: boolean;
    isFinished: boolean;
    hasCapacity: boolean;
}

export type TournamentStatusFilter = 'all' | 'active' | 'upcoming' | 'past';

/**
 * Props for the Tournaments/Show page.
 */
export interface TournamentShowProps {
    tournament: Tournament;
    standings: Standing[];
    participants: Participant[];
    currentRound: Round | null;
    userRegistration: Participant | null;
    canRegister: boolean;
}

/**
 * Props for the Tournaments/Standings page.
 */
export interface TournamentStandingsProps {
    tournament: Tournament;
    standings: Standing[];
}

/**
 * Props for the Tournaments/Rounds page.
 */
export interface TournamentRoundsProps {
    tournament: Tournament;
    rounds: RoundWithMatches[];
}

/**
 * Props for the event tournament section component.
 */
export interface EventTournamentSectionProps {
    tournament: Tournament | null;
    userRegistration: Participant | null;
    canRegister: boolean;
}

/**
 * Check-in window status.
 */
export type CheckInWindowStatus = 'not_available' | 'not_yet' | 'open' | 'closed';

/**
 * Check-in window information.
 */
export interface CheckInWindow {
    status: CheckInWindowStatus;
    opens_at: string | null;
    closes_at: string | null;
}

/**
 * Props for the Tournaments/CheckIn page.
 */
export interface TournamentCheckInProps {
    tournament: Tournament;
    userRegistration: Participant | null;
    checkInWindow: CheckInWindow;
}

/**
 * Next match information for user tournaments.
 */
export interface NextMatch {
    matchId: string;
    roundNumber: number;
    tableNumber: number | null;
    opponentId: string | null;
    opponentName: string | null;
    isBye: boolean;
}

/**
 * User's tournament participation data.
 */
export interface UserTournament {
    id: string;
    name: string;
    slug: string;
    imagePublicId: string | null;
    status: TournamentStatus;
    statusLabel: string;
    statusColor: string;
    startsAt: string | null;
    eventName: string | null;
    totalParticipants: number;
    // Participant data
    participantId: string;
    participantStatus: ParticipantStatus;
    participantStatusLabel: string;
    participantStatusColor: string;
    // Standings data
    position: number | null;
    points: number;
    // Next match
    nextMatch: NextMatch | null;
    // Check-in
    canCheckIn: boolean;
    checkInDeadline: string | null;
    // Classification
    isUpcoming: boolean;
    isInProgress: boolean;
    isPast: boolean;
}

/**
 * Props for the Tournaments/MyTournaments page.
 */
export interface MyTournamentsPageProps {
    upcoming: UserTournament[];
    inProgress: UserTournament[];
    past: UserTournament[];
}

/**
 * Profile tournaments data from Inertia shared props.
 */
export interface ProfileTournamentsData {
    upcoming: UserTournament[];
    inProgress: UserTournament[];
    past: UserTournament[];
    total: number;
}
