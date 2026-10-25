import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import Show from '../../resources/js/pages/Tournaments/Show.vue';
import type { Tournament } from '../../resources/js/types/tournaments';
import { tournament } from './support/fixtures';
import { linkWithText, nestedInteractiveLinks } from './support/interactive';

function mountPage(overrides: Partial<Tournament> = {}): VueWrapper {
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
    it('goes back to the tournament listing', () => {
        expect(linkWithText(mountPage(), 'Volver')?.attributes('href')).toBe('/torneos');
    });

    // The same key is the lowercase noun of «12 / 16 participantes»; the heading needs its own
    it('capitalizes the participants heading', () => {
        const headings = mountPage().findAll('h3').map((heading) => heading.text());

        expect(headings).toContain('Participantes');
        expect(headings).not.toContain('participantes');
    });

    it('links to the standings and the rounds without a button inside each link', () => {
        const wrapper = mountPage(started);

        expect(linkWithText(wrapper, 'Clasificación')?.attributes('href')).toBe('/torneos/torneo-de-primavera/clasificacion');
        expect(linkWithText(wrapper, 'Rondas')?.attributes('href')).toBe('/torneos/torneo-de-primavera/rondas');
        expect(nestedInteractiveLinks(wrapper)).toEqual([]);
    });
});
