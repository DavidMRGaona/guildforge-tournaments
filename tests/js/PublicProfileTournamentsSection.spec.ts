import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import PublicProfileTournamentsSection from '../../resources/js/components/profile/PublicProfileTournamentsSection.vue';
import type {
    PublicProfileTournament,
    PublicProfileTournamentsProps,
} from '../../resources/js/types/tournaments';

function entry(overrides: Partial<PublicProfileTournament> = {}): PublicProfileTournament {
    return {
        id: 'tournament-1',
        name: 'Liga de otoño',
        url: '/torneos/liga-de-otono',
        eventTitle: 'Jornadas de otoño',
        startsAt: '2026-11-14T10:00:00+01:00',
        round: null,
        position: null,
        points: null,
        ...overrides,
    };
}

function mountSection(props: Partial<PublicProfileTournamentsProps> = {}): VueWrapper {
    return mount(PublicProfileTournamentsSection, {
        props: { ongoing: [], upcoming: [], recent: [], ...props },
    });
}

describe('PublicProfileTournamentsSection', () => {
    it('draws a heading only for the groups that have tournaments', () => {
        const wrapper = mountSection({
            ongoing: [entry({ id: 'a', round: 2 })],
            recent: [entry({ id: 'b', position: 1, points: 9 })],
        });

        expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual([
            'En curso',
            'Últimos resultados',
        ]);
        expect(wrapper.find('[data-group="upcoming"]').exists()).toBe(false);
    });

    it('links each tournament to its page and names its event', () => {
        const wrapper = mountSection({ upcoming: [entry()] });

        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe('/torneos/liga-de-otono');
        expect(link.text()).toBe('Liga de otoño');
        expect(wrapper.text()).toContain('Jornadas de otoño');
    });

    it('shows the round under way and the place with its points', () => {
        const wrapper = mountSection({
            ongoing: [entry({ round: 3, position: 2, points: 7 })],
        });

        expect(wrapper.find('[data-field="round"]').text()).toBe('Ronda 3');
        expect(wrapper.find('[data-field="position"]').text()).toBe('Puesto 2');
        expect(wrapper.find('[data-field="points"]').text()).toBe('7 puntos');
    });

    it('says one point in the singular', () => {
        const wrapper = mountSection({ recent: [entry({ position: 5, points: 1 })] });

        expect(wrapper.find('[data-field="points"]').text()).toBe('1 punto');
    });

    it('draws neither round, place nor points when the server sends none', () => {
        const wrapper = mountSection({ upcoming: [entry()] });

        expect(wrapper.find('[data-field="round"]').exists()).toBe(false);
        expect(wrapper.find('[data-field="position"]').exists()).toBe(false);
        expect(wrapper.find('[data-field="points"]').exists()).toBe(false);
    });

    it('draws the date of the event in the venue zone, and none when there is none', () => {
        const dated = mountSection({
            upcoming: [entry({ startsAt: '2026-11-14T23:30:00+01:00' })],
        });
        const undated = mountSection({ upcoming: [entry({ startsAt: null })] });

        expect(dated.text()).toMatch(/14/);
        expect(dated.find('svg').exists()).toBe(true);
        expect(undated.find('svg').exists()).toBe(false);
    });

    it('draws names as text, never as markup', () => {
        const wrapper = mountSection({
            upcoming: [
                entry({ name: '<img src=x onerror=alert(1)>', eventTitle: '<b>evento</b>' }),
            ],
        });

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('b').exists()).toBe(false);
        expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
    });
});
