export default {
    tournaments: {
        title: 'Tournaments',
        description: 'Tournament information',

        // Status labels
        status: {
            draft: 'Draft',
            registration_open: 'Registration open',
            registration_closed: 'Registration closed',
            in_progress: 'In progress',
            finished: 'Finished',
            cancelled: 'Cancelled',
        },

        // Participant status
        participant_status: {
            registered: 'Registered',
            confirmed: 'Confirmed',
            checked_in: 'Checked in',
            withdrawn: 'Withdrawn',
            disqualified: 'Disqualified',
        },

        // Round status
        round_status: {
            pending: 'Pending',
            in_progress: 'In progress',
            finished: 'Finished',
        },

        // Match result
        match_result: {
            pending: 'Pending',
            player1_win: 'Player 1 wins',
            player2_win: 'Player 2 wins',
            draw: 'Draw',
            double_loss: 'Double loss',
            bye: 'Bye',
        },

        // Record format
        record: {
            win_short: 'W',
            draw_short: 'D',
            loss_short: 'L',
        },

        // Listing page
        listing: {
            subtitle: 'Compete in our tournaments',
            filterBy: 'Filter by',
            noTournaments: 'No tournaments available',
            registrationOpen: 'Registration open',
            startedAt: 'Started on',
            completedAt: 'Completed on',
            registrationOpensAt: 'Registration opens',
            roundProgress: 'Round {current} of {max}',
            viewTournament: 'View tournament: {name}',
            filters: {
                all: 'All',
                active: 'Active',
                upcoming: 'Upcoming',
                past: 'Past',
            },
        },

        // Public pages
        public: {
            event_tournament: 'Event tournament',
            view_tournament: 'View tournament',
            participants: 'participants',
            current_round: 'Current round',
            round_label: 'Round {current}/{max}',
            spots_available: 'Spots available',
            spots_remaining: '{count} spots available',
            tournament_full: 'Tournament full',
            register: 'Register',
            withdraw: 'Cancel registration',
            withdraw_title: 'Cancel registration',
            withdraw_confirm: 'Are you sure you want to cancel your registration?',
            gdpr_consent: 'I accept the processing of my data according to the',
            privacy_policy: 'privacy policy',
            already_registered: 'Already registered',
            registered_status: 'Status: {status}',
            registration_open: 'Registration open',
            cannot_register: 'Cannot register',
            login_to_register: 'Login to register',
            started_at: 'Started',
            current_round_title: 'Round {round} in progress',
            matches_completed: '{completed} of {total} matches completed',
            standings: 'Standings',
            view_all_standings: 'View full standings',
            player: 'Player',
            points: 'Points',
            record: 'Record',
            matches: 'Matches',
            wins: 'W',
            draws: 'D',
            losses: 'L',
            buchholz: 'Buchholz',
            owp: 'OMW%',
            no_standings: 'No standings available yet',
            standings_description: 'Tournament standings for {name}',
            tiebreakers_legend: 'Tiebreaker criteria',
            buchholz_description: 'Sum of all opponents points',
            owp_description: 'Opponents match-win percentage',
            rounds: 'Rounds',
            rounds_description: 'Tournament rounds and matches for {name}',
            no_rounds: 'No rounds generated yet',
            round_number: 'Round {number}',
            matches_label: 'matches',
            no_matches: 'No matches in this round',
            bye: 'Bye (resting)',
            disputed: 'Disputed',
            pending_confirmation: 'Pending confirmation',
            registered_participants: 'Registered participants',
            no_participants_yet: 'No participants registered yet',
            guest_registration: 'Guest registration',
            guest_name: 'Name',
            guest_email: 'Email',
            registered_successfully: 'You have registered successfully',
            withdrawn_successfully: 'You have cancelled your registration successfully',
            registration_error: 'Registration error',
        },
    },
};
