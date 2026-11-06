import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../../resources/js/pages/Tournaments/Index.vue';
import MyTournaments from '../../resources/js/pages/Tournaments/MyTournaments.vue';

const noTournaments = {
    tournaments: {
        data: [],
        meta: { currentPage: 1, lastPage: 1, perPage: 12, total: 0 },
        links: { first: '/torneos', last: '/torneos', prev: null, next: null },
    },
    currentFilter: 'all' as const,
};

// The site layout already renders the page's <main>: a second one nests landmarks
describe('tournament listings', () => {
    it('does not open a main landmark of its own in the listing', () => {
        const wrapper = mount(Index, { props: noTournaments });

        expect(wrapper.find('main').exists()).toBe(false);
    });

    it('does not open a main landmark of its own in my tournaments', () => {
        const wrapper = mount(MyTournaments, { props: { upcoming: [], inProgress: [], past: [] } });

        expect(wrapper.find('main').exists()).toBe(false);
    });

    it('links the listing heading to the guide on how tournaments work', () => {
        const wrapper = mount(Index, { props: noTournaments });

        expect(wrapper.get('h1 [data-testid="help-link"]').attributes('data-guide')).toBe(
            'tournaments.overview'
        );
    });

    it('links my tournaments to the guide on following a tournament', () => {
        const wrapper = mount(MyTournaments, { props: { upcoming: [], inProgress: [], past: [] } });

        expect(wrapper.get('h1 [data-testid="help-link"]').attributes('data-guide')).toBe(
            'tournaments.follow-tournament'
        );
    });
});
