import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import Show from '../../resources/js/pages/Tournaments/Show.vue';
import type { Tournament, TournamentShowProps } from '../../resources/js/types/tournaments';
import { playerMatch, tournament } from './support/fixtures';
import { linkWithText, nestedInteractiveLinks } from './support/interactive';

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
    });

    it('offers a player their survey without the organizer link', () => {
        const wrapper = mountPage(
            {},
            {
                surveyLinks: {
                    manageUrl: null,
                    surveys: [{ title: 'Valoración', url: '/encuestas/1', action: 'respond' }],
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
});
