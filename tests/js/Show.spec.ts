import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import Show from '../../resources/js/pages/Tournaments/Show.vue';
import type { Tournament, TournamentShowProps } from '../../resources/js/types/tournaments';
import { playerMatch, registration, tournament } from './support/fixtures';
import { linkWithText, nestedInteractiveLinks } from './support/interactive';
import { signIn } from './support/page';

function mountPage(
    overrides: Partial<Tournament> = {},
    props: Partial<TournamentShowProps> = {}
): VueWrapper {
    return mount(Show, {
        props: {
            tournament: tournament(overrides),
            standings: [],
            participants: [],
            currentRound: null,
            userRegistration: null,
            canRegister: false,
            canCheckIn: false,
            checkInWindow: { status: 'not_available', opens_at: null, closes_at: null },
            guestConfirmation: null,
            ...props,
        },
    });
}

const started: Partial<Tournament> = {
    status: 'in_progress',
    display_status: 'in_progress',
    status_label: 'En curso',
    current_round: 1,
    is_registration_open: false,
    is_in_progress: true,
};

describe('Show', () => {
    it('links its heading to the guide on signing up', () => {
        const wrapper = mountPage();

        expect(wrapper.get('h1 [data-testid="help-link"]').attributes('data-guide')).toBe(
            'tournaments.sign-up'
        );
    });

    it('shows the player their match of the current round', () => {
        const wrapper = mountPage(started, { myMatch: playerMatch() });

        expect(wrapper.text()).toContain('Tu partida');
        expect(wrapper.text()).toContain('Enviar resultado');
    });

    it('shows no match block to someone who does not play', () => {
        const wrapper = mountPage(started, { myMatch: null });

        expect(wrapper.text()).not.toContain('Tu partida');
    });

    it('links to the tournament surveys and to the ones sent to the person', () => {
        const wrapper = mountPage(
            {},
            {
                surveyLinks: {
                    manageUrl: '/encuestas/torneo/ejemplo',
                    surveys: [
                        { title: 'Valoración', url: '/encuestas/1', action: 'respond' },
                        { title: 'Ronda final', url: '/encuestas/2', action: 'view_results' },
                    ],
                    guideId: 'surveys.organizer-end-survey',
                },
            }
        );

        expect(wrapper.text()).toContain('Encuestas');
        expect(linkWithText(wrapper, 'Encuestas del torneo')?.attributes('href')).toBe(
            '/encuestas/torneo/ejemplo'
        );
        expect(linkWithText(wrapper, 'Responder «Valoración»')?.attributes('href')).toBe(
            '/encuestas/1'
        );
        expect(linkWithText(wrapper, 'Resultados de «Ronda final»')?.attributes('href')).toBe(
            '/encuestas/2'
        );
        expect(wrapper.get('section [data-testid="help-link"]').attributes('data-guide')).toBe(
            'surveys.organizer-end-survey'
        );
    });

    it('offers a player their survey without the organizer link', () => {
        const wrapper = mountPage(
            {},
            {
                surveyLinks: {
                    manageUrl: null,
                    surveys: [{ title: 'Valoración', url: '/encuestas/1', action: 'respond' }],
                    guideId: null,
                },
            }
        );

        expect(linkWithText(wrapper, 'Encuestas del torneo')).toBeUndefined();
        expect(linkWithText(wrapper, 'Responder «Valoración»')?.attributes('href')).toBe(
            '/encuestas/1'
        );
    });

    it('shows no surveys without links', () => {
        expect(mountPage().text()).not.toContain('Encuestas del torneo');
        expect(
            mountPage({}, { surveyLinks: null })
                .findAll('h2')
                .map((h) => h.text())
        ).not.toContain('Encuestas');
    });

    it('goes back to the tournament listing', () => {
        expect(linkWithText(mountPage(), 'Volver')?.attributes('href')).toBe('/torneos');
    });

    // The same key is the lowercase noun of «12 / 16 participantes»; the heading needs its own
    it('capitalizes the participants heading', () => {
        const headings = mountPage()
            .findAll('h3')
            .map((heading) => heading.text());

        expect(headings).toContain('Participantes');
        expect(headings).not.toContain('participantes');
    });

    it('links to the standings and the rounds without a button inside each link', () => {
        const wrapper = mountPage(started);

        expect(linkWithText(wrapper, 'Clasificación')?.attributes('href')).toBe(
            '/torneos/torneo-de-primavera/clasificacion'
        );
        expect(linkWithText(wrapper, 'Rondas')?.attributes('href')).toBe(
            '/torneos/torneo-de-primavera/rondas'
        );
        expect(nestedInteractiveLinks(wrapper)).toEqual([]);
    });

    describe('the check-in', () => {
        const withCheckIn: Partial<Tournament> = { requires_check_in: true };
        // Madrid time: opens at 17:30 and closes at 18:00 on 14 November
        const openWindow = {
            status: 'open' as const,
            opens_at: '2026-11-14T17:30:00+01:00',
            closes_at: '2026-11-14T18:00:00+01:00',
        };
        const upcomingWindow = { ...openWindow, status: 'not_yet' as const };

        it('links a visitor to the check-in while it is open', () => {
            const wrapper = mountPage(withCheckIn, { checkInWindow: openWindow });

            expect(wrapper.text()).toContain(
                'Si te inscribiste, haz el check-in con el email de tu inscripción hasta las 18:00.'
            );
            expect(linkWithText(wrapper, 'Hacer check-in')?.attributes('href')).toBe(
                '/torneos/torneo-de-primavera/check-in'
            );
        });

        it('tells a visitor when it opens', () => {
            const wrapper = mountPage(withCheckIn, { checkInWindow: upcomingWindow });

            expect(wrapper.text()).toContain(
                'El check-in abre el 14 de noviembre a las 17:30. Si te inscribiste, lo harás con el email de tu inscripción.'
            );
            expect(linkWithText(wrapper, 'Ir al check-in')?.attributes('href')).toBe(
                '/torneos/torneo-de-primavera/check-in'
            );
        });

        it('shows nothing when the participants cannot check in themselves or it closed', () => {
            for (const status of ['not_available', 'closed'] as const) {
                const wrapper = mountPage(withCheckIn, {
                    checkInWindow: { ...openWindow, status },
                });

                expect(linkWithText(wrapper, 'Hacer check-in')).toBeUndefined();
                expect(linkWithText(wrapper, 'Ir al check-in')).toBeUndefined();
            }
        });

        it('tells a registered account when it opens', () => {
            signIn();
            const wrapper = mountPage(withCheckIn, {
                checkInWindow: upcomingWindow,
                userRegistration: registration(),
            });

            expect(wrapper.text()).toContain('El check-in abre el 14 de noviembre a las 17:30.');
            expect(wrapper.text()).not.toContain('Si te inscribiste');
        });

        it('does not repeat it to an account already checked in', () => {
            signIn();
            const wrapper = mountPage(withCheckIn, {
                checkInWindow: upcomingWindow,
                userRegistration: registration({
                    status: 'checked_in',
                    status_label: 'Registrado',
                }),
            });

            expect(wrapper.text()).not.toContain('El check-in abre');
        });
    });

    describe('when the person cannot register', () => {
        it('asks a guest to sign in', () => {
            const wrapper = mountPage();

            expect(wrapper.text()).toContain('No puedes inscribirte');
            expect(wrapper.text()).toContain('Inicia sesión para inscribirte');
            expect(linkWithText(wrapper, 'Iniciar sesión')?.attributes('href')).toBe(
                '/iniciar-sesion'
            );
        });

        it('tells a signed-in person that the tournament is full', () => {
            signIn();
            const wrapper = mountPage({ max_participants: 4, participant_count: 4 });

            expect(wrapper.text()).toContain('No puedes inscribirte');
            expect(wrapper.text()).toContain('El torneo ha completado todas sus plazas.');
            expect(wrapper.text()).not.toContain('Inicia sesión');
        });

        it('tells a signed-in person that the tournament is limited to some roles', () => {
            signIn();
            const wrapper = mountPage({ max_participants: 16, participant_count: 4 });

            expect(wrapper.text()).toContain(
                'Este torneo está reservado a ciertos roles y tu cuenta no tiene ninguno.'
            );
            expect(wrapper.text()).not.toContain('Inicia sesión');
        });
    });

    it('draws the site box for the guest privacy consent', async () => {
        const wrapper = mount(Show, {
            props: {
                tournament: tournament({ allow_guests: true }),
                standings: [],
                participants: [],
                currentRound: null,
                userRegistration: null,
                canRegister: true,
                canCheckIn: false,
                checkInWindow: { status: 'not_available', opens_at: null, closes_at: null },
                guestConfirmation: null,
            },
            attachTo: document.body,
        });

        await wrapper
            .findAll('button')
            .find((candidate) => candidate.text() === 'Inscribirse')
            ?.trigger('click');

        const consent = document.getElementById('guest-gdpr');
        expect(consent?.classList.contains('sr-only')).toBe(true);
        expect(consent?.hasAttribute('required')).toBe(true);
        expect(
            document.querySelector('label[for="guest-gdpr"] [data-testid="checkbox-box"]')
        ).not.toBeNull();
        wrapper.unmount();
    });
});
