import type { Participant, Tournament } from '../../../resources/js/types/tournaments';

/** A tournament open for registration; override what the test is about. */
export function tournament(overrides: Partial<Tournament> = {}): Tournament {
    return {
        id: 'tournament-1',
        event_id: 'event-1',
        name: 'Torneo de primavera',
        slug: 'torneo-de-primavera',
        description: null,
        image_public_id: null,
        status: 'registration_open',
        display_status: 'registration_open',
        status_label: 'Inscripciones abiertas',
        max_rounds: null,
        current_round: 0,
        max_participants: 16,
        min_participants: null,
        participant_count: 4,
        score_weights: [],
        tiebreakers: [],
        allow_guests: false,
        allowed_roles: [],
        result_reporting: 'admin_only',
        requires_check_in: false,
        check_in_starts_before: null,
        registration_opens_at: null,
        registration_closes_at: null,
        started_at: null,
        completed_at: null,
        created_at: '2026-10-01T10:00:00Z',
        updated_at: '2026-10-01T10:00:00Z',
        is_registration_open: true,
        can_withdraw: true,
        is_in_progress: false,
        is_finished: false,
        has_capacity: true,
        recommended_rounds: 3,
        show_participants: true,
        ...overrides,
    };
}

/** The signed-in user's registration. */
export function registration(overrides: Partial<Participant> = {}): Participant {
    return {
        id: 'participant-1',
        tournament_id: 'tournament-1',
        user_id: 'user-1',
        user_name: 'John Doe',
        user_email: 'john.doe@example.com',
        guest_name: null,
        guest_email: null,
        status: 'registered',
        status_label: 'Inscrito',
        seed: null,
        has_received_bye: false,
        registered_at: '2026-10-02T10:00:00Z',
        checked_in_at: null,
        display_name: 'John Doe',
        is_guest: false,
        is_checked_in: false,
        ...overrides,
    };
}
