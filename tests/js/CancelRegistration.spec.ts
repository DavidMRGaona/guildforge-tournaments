import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import CancelRegistration from '../../resources/js/pages/Tournaments/CancelRegistration.vue';

type BlockedReason = 'already_cancelled' | 'tournament_started' | 'tournament_cancelled' | null;

function mountPage(canCancel: boolean, cancelBlockedReason: BlockedReason = null) {
    return mount(CancelRegistration, {
        props: {
            participant: {
                id: 'participant-1',
                status: 'registered',
                is_guest: true,
                guest_name: 'John Doe',
                guest_email: 'john.doe@example.com',
            },
            tournament: { id: 'tournament-1', name: 'Torneo de primavera', eventDate: null },
            token: 'token-123',
            canCancel,
            cancelBlockedReason,
        },
    });
}

describe('CancelRegistration', () => {
    it('cancels the registration of its link', async () => {
        const wrapper = mountPage(true);

        await wrapper.findAll('button').find((button) => button.text() === 'Sí, cancelar inscripción')?.trigger('click');

        expect(router.delete).toHaveBeenCalledWith('/torneos/cancelar/token-123', { preserveScroll: true });
    });

    it.each([
        ['tournament_started', 'El torneo ya ha comenzado: ya no puedes cancelar tu inscripción'],
        ['tournament_cancelled', 'El torneo se ha cancelado'],
        ['already_cancelled', 'Tu inscripción ya ha sido cancelada'],
    ] as const)('says why it cannot cancel (%s)', (reason, message) => {
        const wrapper = mountPage(false, reason);

        expect(wrapper.text()).toContain(message);
        expect(wrapper.text()).not.toContain('Sí, cancelar inscripción');
    });

    it('does not claim the registration was cancelled when the tournament started', () => {
        expect(mountPage(false, 'tournament_started').text()).not.toContain('Tu inscripción ya ha sido cancelada');
    });
});
