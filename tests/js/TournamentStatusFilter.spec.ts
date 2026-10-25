import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import TournamentStatusFilter from '../../resources/js/components/TournamentStatusFilter.vue';

describe('TournamentStatusFilter', () => {
    // Colour alone does not tell a screen reader which filter applies
    it('announces which filter is applied', () => {
        const wrapper = mount(TournamentStatusFilter, { props: { currentFilter: 'upcoming' } });

        const pressed = wrapper.findAll('button').map((button) => [button.text(), button.attributes('aria-pressed')]);

        expect(pressed).toEqual([
            ['Todos', 'false'],
            ['En curso', 'false'],
            ['Próximos', 'true'],
            ['Finalizados', 'false'],
        ]);
    });
});
