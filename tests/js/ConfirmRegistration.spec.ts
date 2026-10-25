import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ConfirmRegistration from '../../resources/js/pages/Tournaments/ConfirmRegistration.vue';
import { linkWithText, nestedInteractiveLinks } from './support/interactive';

describe('ConfirmRegistration', () => {
    it('links back to the tournament with a single link, not a button inside a link', () => {
        const wrapper = mount(ConfirmRegistration, {
            props: {
                confirmation: {
                    state: 'already_confirmed',
                    tournament_name: 'Torneo de primavera',
                    tournament_slug: 'torneo-de-primavera',
                    guest_name: 'John Doe',
                    expires_at: null,
                    seat_held: false,
                },
                token: 'token-123',
                result: null,
            },
        });

        expect(linkWithText(wrapper, 'Ver torneo')?.attributes('href')).toBe('/torneos/torneo-de-primavera');
        expect(nestedInteractiveLinks(wrapper)).toEqual([]);
    });
});
