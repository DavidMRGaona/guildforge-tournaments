import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import Rounds from '../../resources/js/pages/Tournaments/Rounds.vue';
import { roundWithMatches, tournament } from './support/fixtures';

function mountPage(): VueWrapper {
    return mount(Rounds, {
        props: {
            tournament: tournament({ status: 'in_progress', display_status: 'in_progress' }),
            rounds: [
                roundWithMatches({ id: 'round-1', round_number: 1 }),
                roundWithMatches({ id: 'round-2', round_number: 2, status: 'in_progress', status_label: 'En curso' }),
            ],
        },
        attachTo: document.body,
    });
}

function header(wrapper: VueWrapper, roundNumber: number): ReturnType<VueWrapper['findAll']>[number] {
    const found = wrapper.findAll('button').find((button) => button.text().includes(`Ronda ${roundNumber}`));
    if (found === undefined) {
        throw new Error(`No header for round ${roundNumber}`);
    }

    return found;
}

describe('Rounds', () => {
    it('announces whether each round is open', () => {
        const wrapper = mountPage();

        // The round in progress starts open, the others closed
        expect(header(wrapper, 2).attributes('aria-expanded')).toBe('true');
        expect(header(wrapper, 1).attributes('aria-expanded')).toBe('false');
    });

    it('updates the announcement when a round is toggled', async () => {
        const wrapper = mountPage();

        await header(wrapper, 1).trigger('click');
        await header(wrapper, 2).trigger('click');

        expect(header(wrapper, 1).attributes('aria-expanded')).toBe('true');
        expect(header(wrapper, 2).attributes('aria-expanded')).toBe('false');
    });

    it('points each header at the panel with its matches', () => {
        const wrapper = mountPage();

        for (const roundNumber of [1, 2]) {
            const controls = header(wrapper, roundNumber).attributes('aria-controls');
            expect(controls).toBeTruthy();

            const panel = document.getElementById(controls ?? '');
            expect(panel).not.toBeNull();
            expect(panel?.textContent).toContain('John Doe');
        }

        expect(header(wrapper, 1).attributes('aria-controls')).not.toBe(header(wrapper, 2).attributes('aria-controls'));
    });
});
