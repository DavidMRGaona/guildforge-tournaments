import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import EventTournamentSection from '../../resources/js/components/EventTournamentSection.vue';
import type { Participant, Tournament } from '../../resources/js/types/tournaments';
import { registration, tournament } from './support/fixtures';
import { signIn } from './support/page';

// A button that emits every click, disabled or not: reaches the handlers behind the buttons
const AlwaysClickableButton = defineComponent({
    inheritAttrs: false,
    emits: ['click'],
    setup(_props, { emit, slots }) {
        return () => h('button', { type: 'button', onClick: (event: Event) => emit('click', event) }, slots.default?.());
    },
});

function mountSection(
    props: { tournament?: Tournament; userRegistration?: Participant | null; canRegister?: boolean } = {},
    options: { alwaysClickable?: boolean } = {},
) {
    return mount(EventTournamentSection, {
        props: {
            tournament: props.tournament ?? tournament(),
            userRegistration: props.userRegistration ?? null,
            canRegister: props.canRegister ?? true,
            guestConfirmation: null,
        },
        attachTo: document.body,
        global: options.alwaysClickable === true ? { stubs: { BaseButton: AlwaysClickableButton } } : {},
    });
}

function button(wrapper: ReturnType<typeof mountSection>, label: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text() === label);
}

// The dialogs are teleported to <body>, outside the wrapper
function openDialog(): HTMLElement | null {
    return document.body.querySelector<HTMLElement>('[role="dialog"]');
}

function dialogButton(label: string): HTMLButtonElement | undefined {
    return [...(openDialog()?.querySelectorAll('button') ?? [])].find((candidate) => candidate.textContent?.trim() === label);
}

describe('EventTournamentSection', () => {
    let fetchSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    describe('withdrawing', () => {
        it('offers to withdraw while the server allows it', () => {
            signIn();
            const wrapper = mountSection({ userRegistration: registration() });

            expect(button(wrapper, 'Cancelar inscripción')).toBeDefined();
        });

        // The server's flag decides (event not started, tournament not running), not whether
        // registration is still open
        it('does not offer to withdraw when the server does not allow it, even with registration open', () => {
            signIn();
            const wrapper = mountSection({
                tournament: tournament({ can_withdraw: false, is_registration_open: true }),
                userRegistration: registration(),
            });

            expect(button(wrapper, 'Cancelar inscripción')).toBeUndefined();
        });

        it('offers to withdraw after registration closed if the server still allows it', () => {
            signIn();
            const wrapper = mountSection({
                tournament: tournament({ can_withdraw: true, is_registration_open: false }),
                userRegistration: registration(),
            });

            expect(button(wrapper, 'Cancelar inscripción')).toBeDefined();
        });

        it('asks for confirmation before withdrawing', async () => {
            signIn();
            const wrapper = mountSection({ userRegistration: registration() });

            await button(wrapper, 'Cancelar inscripción')?.trigger('click');

            expect(openDialog()?.textContent).toContain('Cancelar inscripción');
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('does not offer to withdraw a registration that is no longer active', () => {
            signIn();
            const wrapper = mountSection({ userRegistration: registration({ status: 'withdrawn' }) });

            expect(button(wrapper, 'Cancelar inscripción')).toBeUndefined();
        });
    });

    describe('verification gate', () => {
        it('disables registering with its reason while the account must verify its email', async () => {
            signIn({ mustVerifyEmail: true });
            const wrapper = mountSection();

            const register = button(wrapper, 'Inscribirse');
            expect(register?.attributes('disabled')).toBeDefined();

            const reason = wrapper.find(`#${register?.attributes('aria-describedby')}`);
            expect(reason.text()).toContain('Para inscribirte, verifica tu email.');
            expect(reason.find('a').attributes('href')).toBe('/verificar-email');

            await register?.trigger('click');
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        it('disables withdrawing with its own reason while the account must verify its email', async () => {
            signIn({ mustVerifyEmail: true });
            const wrapper = mountSection({ userRegistration: registration() });

            const withdraw = button(wrapper, 'Cancelar inscripción');
            expect(withdraw?.attributes('disabled')).toBeDefined();
            expect(wrapper.find(`#${withdraw?.attributes('aria-describedby')}`).text()).toContain('verifica tu email');

            await withdraw?.trigger('click');
            expect(openDialog()).toBeNull();
            expect(fetchSpy).not.toHaveBeenCalled();
        });

        // The handlers check too, whatever the button lets through
        it('sends nothing from the handlers while the account must verify its email', async () => {
            signIn({ mustVerifyEmail: true });
            const registering = mountSection({}, { alwaysClickable: true });
            await button(registering, 'Inscribirse')?.trigger('click');

            const withdrawing = mountSection({ userRegistration: registration() }, { alwaysClickable: true });
            await button(withdrawing, 'Cancelar inscripción')?.trigger('click');
            await flushPromises();

            expect(fetchSpy).not.toHaveBeenCalled();
            expect(openDialog()).toBeNull();
        });

        it('leaves the buttons alone for an account that needs no verification', () => {
            signIn();
            const wrapper = mountSection();

            expect(button(wrapper, 'Inscribirse')?.attributes('disabled')).toBeUndefined();
            expect(wrapper.text()).not.toContain('verifica tu email');
        });

        // Verification required after all (changed in another tab): to the notice, no error
        describe('when the server asks for the verification anyway', () => {
            beforeEach(() => {
                fetchSpy.mockResolvedValue(new Response(JSON.stringify({ error: 'email_verification_required' }), {
                    status: 403,
                    headers: { 'Content-Type': 'application/json', 'X-Email-Verification-Required': '1' },
                }));
            });

            it('leads a registration to the notice', async () => {
                signIn();
                const wrapper = mountSection();

                await button(wrapper, 'Inscribirse')?.trigger('click');
                await flushPromises();

                expect(fetchSpy).toHaveBeenCalledWith('/torneos/tournament-1/inscripcion', expect.objectContaining({ method: 'POST' }));
                expect(router.visit).toHaveBeenCalledWith('/verificar-email');
                expect(router.reload).not.toHaveBeenCalled();
            });

            it('closes the withdraw dialog and leads to the notice', async () => {
                signIn();
                const wrapper = mountSection({ userRegistration: registration() });

                await button(wrapper, 'Cancelar inscripción')?.trigger('click');
                expect(openDialog()).not.toBeNull();
                dialogButton('Cancelar inscripción')?.click();
                await flushPromises();

                expect(fetchSpy).toHaveBeenCalledWith('/torneos/tournament-1/inscripcion', expect.objectContaining({ method: 'DELETE' }));
                expect(openDialog()).toBeNull();
                expect(router.visit).toHaveBeenCalledWith('/verificar-email');
            });

            // Signed in meanwhile, in another tab, with an account that must verify
            it('closes the guest dialog and leads to the notice', async () => {
                const wrapper = mountSection({ tournament: tournament({ allow_guests: true }) });

                await button(wrapper, 'Inscribirse')?.trigger('click');
                expect(openDialog()).not.toBeNull();
                openDialog()?.querySelector('form')?.dispatchEvent(new Event('submit'));
                await flushPromises();

                expect(fetchSpy).toHaveBeenCalledOnce();
                expect(openDialog()).toBeNull();
                expect(router.visit).toHaveBeenCalledWith('/verificar-email');
            });
        });
    });
});
