import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../../resources/js/pages/Tournaments/Index.vue';
import MyTournaments from '../../resources/js/pages/Tournaments/MyTournaments.vue';

// The site layout already renders the page's <main>: a second one nests landmarks
describe('tournament listings', () => {
    it('does not open a main landmark of its own in the listing', () => {
        const wrapper = mount(Index, {
            props: {
                tournaments: {
                    data: [],
                    meta: { currentPage: 1, lastPage: 1, perPage: 12, total: 0 },
                    links: { first: '/torneos', last: '/torneos', prev: null, next: null },
                },
                currentFilter: 'all',
            },
        });

        expect(wrapper.find('main').exists()).toBe(false);
    });

    it('does not open a main landmark of its own in my tournaments', () => {
        const wrapper = mount(MyTournaments, { props: { upcoming: [], inProgress: [], past: [] } });

        expect(wrapper.find('main').exists()).toBe(false);
    });
});
