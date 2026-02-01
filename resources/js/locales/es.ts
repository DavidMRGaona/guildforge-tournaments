export default {
    tournaments: {
        title: 'Torneos',
        description: 'Información del torneo',

        // My tournaments page
        my_tournaments: {
            title: 'Mis torneos',
            subtitle: 'Tus inscripciones y participaciones en torneos',
            browse_tournaments: 'Explorar torneos',
            upcoming: 'Próximos',
            in_progress: 'En curso',
            history: 'Historial',
            empty_title: 'No estás inscrito en ningún torneo',
            empty_description: 'Explora los torneos disponibles y únete a competir',
            no_upcoming: 'No tienes torneos próximos',
            position: 'Posición',
            position_of: '{position}º de {total}',
            points: 'pts',
            next_match: 'Próxima partida',
            round: 'Ronda',
            table: 'Mesa',
            vs: 'vs.',
            bye: 'Descanso',
            check_in_now: 'Hacer check-in',
            until: 'hasta',
            view_tournament: 'Ver torneo',
            view_all: 'Ver todos mis torneos',
            load_more: 'Cargar más ({count})',
        },

        // Profile section
        profile: {
            tabLabel: 'Torneos',
        },

        // Status labels
        status: {
            draft: 'Borrador',
            registration_open: 'Inscripción abierta',
            registration_closed: 'Inscripción cerrada',
            in_progress: 'En curso',
            finished: 'Finalizado',
            cancelled: 'Cancelado',
        },

        // Participant status
        participant_status: {
            registered: 'Inscrito',
            confirmed: 'Confirmado',
            checked_in: 'Registrado',
            withdrawn: 'Retirado',
            disqualified: 'Descalificado',
        },

        // Round status
        round_status: {
            pending: 'Pendiente',
            in_progress: 'En curso',
            finished: 'Finalizada',
        },

        // Match result
        match_result: {
            pending: 'Pendiente',
            player1_win: 'Victoria J1',
            player2_win: 'Victoria J2',
            draw: 'Empate',
            double_loss: 'Doble derrota',
            bye: 'Bye',
        },

        // Record format
        record: {
            win_short: 'V',
            draw_short: 'E',
            loss_short: 'D',
        },

        // Listing page
        listing: {
            subtitle: 'Compite en nuestros torneos',
            filterBy: 'Filtrar por',
            noTournaments: 'No hay torneos disponibles',
            registrationOpen: 'Inscripción abierta',
            startedAt: 'Iniciado el',
            completedAt: 'Finalizado el',
            registrationOpensAt: 'Inscripción desde',
            roundProgress: 'Ronda {current} de {max}',
            viewTournament: 'Ver torneo: {name}',
            filters: {
                all: 'Todos',
                active: 'En curso',
                upcoming: 'Próximos',
                past: 'Finalizados',
            },
        },

        // Public pages
        public: {
            event_tournament: 'Torneo del evento',
            view_tournament: 'Ver torneo',
            participants: 'participantes',
            current_round: 'Ronda actual',
            round_label: 'Ronda {current}/{max}',
            spots_available: 'Plazas disponibles',
            spots_remaining: '{count} plazas disponibles',
            tournament_full: 'Torneo completo',
            register: 'Inscribirse',
            withdraw: 'Cancelar inscripción',
            withdraw_title: 'Cancelar inscripción',
            withdraw_confirm: '¿Estás seguro de que quieres cancelar tu inscripción?',
            gdpr_consent: 'Acepto el tratamiento de mis datos según la',
            privacy_policy: 'política de privacidad',
            already_registered: 'Ya estás inscrito',
            registered_status: 'Estado: {status}',
            registration_open: 'Inscripción abierta',
            cannot_register: 'No puedes inscribirte',
            login_to_register: 'Inicia sesión para inscribirte',
            started_at: 'Iniciado',
            current_round_title: 'Ronda {round} en curso',
            matches_completed: '{completed} de {total} partidas completadas',
            standings: 'Clasificación',
            view_all_standings: 'Ver clasificación completa',
            player: 'Jugador',
            points: 'Puntos',
            record: 'Récord',
            matches: 'Partidas',
            wins: 'V',
            draws: 'E',
            losses: 'D',
            buchholz: 'Buchholz',
            owp: 'OMW%',
            no_standings: 'Aún no hay clasificación disponible',
            standings_description: 'Clasificación del torneo {name}',
            tiebreakers_legend: 'Criterios de desempate',
            buchholz_description: 'Suma de los puntos de todos los oponentes',
            owp_description: 'Porcentaje de victorias de los oponentes',
            rounds: 'Rondas',
            rounds_description: 'Rondas y partidas del torneo {name}',
            no_rounds: 'Aún no se han generado rondas',
            round_number: 'Ronda {number}',
            matches_label: 'partidas',
            no_matches: 'No hay partidas en esta ronda',
            bye: 'Bye (descansa)',
            disputed: 'Disputado',
            pending_confirmation: 'Pendiente de confirmación',
            registered_participants: 'Participantes inscritos',
            no_participants_yet: 'Aún no hay participantes inscritos',
            guest_registration: 'Registro como invitado',
            guest_name: 'Nombre',
            guest_email: 'Correo electrónico',
            registered_successfully: 'Te has inscrito correctamente',
            withdrawn_successfully: 'Has cancelado tu inscripción correctamente',
            registration_error: 'Error al inscribirse',
        },
    },
};
