import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import CheckIn from '../../resources/js/pages/Tournaments/CheckIn.vue';
import type { TournamentCheckInProps } from '../../resources/js/types/tournaments';
import { registration, tournament } from './support/fixtures';
import { signIn } from './support/page';

function mountPage(props: Partial<TournamentCheckInProps> = {}): VueWrapper {
    return mount(CheckIn, {
        props: {
            tournament: tournament(),
            userRegistration: null,
            checkInWindow: { status: 'open', opens_at: null, closes_at: null },
            ...props,
        } as TournamentCheckInProps,
    });
}

describe('CheckIn', () => {
    it('labels the email field of a guest with a translated text', () => {
        const wrapper = mountPage();

        const label = wrapper.get('label[for="check-in-email"]');

        expect(label.text()).toContain('Email');
        expect(label.text()).not.toContain('common.email');
        expect(wrapper.text()).toContain('Introduce tu email para hacer check-in');
    });

    it('does not ask a signed-in person for an email they do not have to type', () => {
        signIn();
        const wrapper = mountPage({ userRegistration: registration({ status: 'registered' }) });

        expect(wrapper.find('#check-in-email').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Introduce tu email');
        expect(wrapper.text()).toContain('Pulsa el botón para confirmar que estás aquí.');
    });
});
