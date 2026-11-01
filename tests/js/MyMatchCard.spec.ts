import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import MyMatchCard from '../../resources/js/components/MyMatchCard.vue';
import type { PlayerMatch } from '../../resources/js/types/tournaments';
import { matchReport, playerMatch } from './support/fixtures';
import { signIn } from './support/page';

function mountCard(overrides: Partial<PlayerMatch> = {}): VueWrapper {
    return mount(MyMatchCard, { props: { playerMatch: playerMatch(overrides) } });
}

function button(wrapper: VueWrapper, label: string): ReturnType<VueWrapper['find']> | undefined {
    return wrapper.findAll('button').find((candidate) => candidate.text() === label);
}

function okResponse(body: object = { message: 'Hecho' }): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

describe('MyMatchCard', () => {
    let fetchSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        signIn();
        fetchSpy = vi.fn().mockResolvedValue(okResponse());
        vi.stubGlobal('fetch', fetchSpy);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('shows the form when the player can report', () => {
        const wrapper = mountCard();

        expect(wrapper.text()).toContain('Tu partida');
        expect(wrapper.text()).toContain('Mesa 3');
        expect(wrapper.text()).toContain('Jane Doe');
        expect(wrapper.find('fieldset legend').text()).toBe('Resultado');
        const labels = wrapper
            .findAll('input[type="radio"]')
            .map((radio) => wrapper.find(`label[for="${radio.attributes('id')}"]`).text());
        expect(labels).toEqual(['Gana John Doe', 'Empate', 'Gana Jane Doe']);
        expect(button(wrapper, 'Enviar resultado')).toBeDefined();
    });

    it('sends the absolute result for the chosen name', async () => {
        const wrapper = mountCard();

        await wrapper.findAll('input[type="radio"]')[2].setValue(true);
        await wrapper.find('input[name="player_1_score"]').setValue('10');
        await wrapper.find('input[name="player_2_score"]').setValue('20');
        await wrapper.find('input[name="player_1_stats.vp"]').setValue('10');
        await wrapper.find('input[name="player_2_stats.vp"]').setValue('20');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(fetchSpy).toHaveBeenCalledOnce();
        const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/torneos/partidas/match-1/resultado');
        expect(JSON.parse(String(init.body))).toEqual({
            result: 'player_two_win',
            player_1_score: 10,
            player_2_score: 20,
            player_1_stats: { vp: 10 },
            player_2_stats: { vp: 20 },
        });
        expect(router.reload).toHaveBeenCalled();
    });

    it('shows server validation errors next to their field', async () => {
        fetchSpy.mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Error',
                    errors: { 'player_1_stats.vp': ['Demasiados puntos'] },
                }),
                {
                    status: 422,
                    headers: { 'Content-Type': 'application/json' },
                }
            )
        );
        const wrapper = mountCard();

        await wrapper.findAll('input[type="radio"]')[0].setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const input = wrapper.find('input[name="player_1_stats.vp"]');
        expect(input.attributes('aria-invalid')).toBe('true');
        const errorId = input.attributes('aria-describedby');
        expect(errorId).toBeDefined();
        expect(wrapper.find(`#${errorId}`).text()).toBe('Demasiados puntos');
    });

    it('shows confirm and dispute to the rival only', () => {
        const rival = mountCard({
            report: matchReport(),
            actions: {
                can_report: false,
                can_correct: false,
                can_confirm: true,
                can_dispute: true,
            },
        });

        expect(rival.text()).toContain('Jane Doe ha informado: Gana Jane Doe (10-20)');
        expect(button(rival, 'Confirmar')).toBeDefined();
        expect(button(rival, 'Discutir')).toBeDefined();

        const reporter = mountCard({
            report: matchReport({ reported_by_name: 'John Doe' }),
            actions: {
                can_report: false,
                can_correct: true,
                can_confirm: false,
                can_dispute: false,
            },
        });

        expect(button(reporter, 'Confirmar')).toBeUndefined();
        expect(button(reporter, 'Discutir')).toBeUndefined();
        expect(reporter.text()).toContain('Esperando a que Jane Doe lo confirme');
        expect(button(reporter, 'Corregir')).toBeDefined();
    });

    it('confirms the version it shows', async () => {
        const wrapper = mountCard({
            report: matchReport({ fingerprint: 'seen-version' }),
            actions: {
                can_report: false,
                can_correct: false,
                can_confirm: true,
                can_dispute: true,
            },
        });

        await button(wrapper, 'Confirmar')?.trigger('click');
        await flushPromises();

        const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/torneos/partidas/match-1/confirmar');
        expect(JSON.parse(String(init.body))).toEqual({ report: 'seen-version' });
    });

    it('dispute needs a reason', async () => {
        const wrapper = mountCard({
            report: matchReport(),
            actions: {
                can_report: false,
                can_correct: false,
                can_confirm: true,
                can_dispute: true,
            },
        });

        await button(wrapper, 'Discutir')?.trigger('click');
        const reason = wrapper.find('textarea[name="reason"]');
        expect(reason.attributes('required')).toBeDefined();
        expect(reason.attributes('maxlength')).toBe('500');

        await button(wrapper, 'Enviar')?.trigger('click');
        await flushPromises();
        expect(fetchSpy).not.toHaveBeenCalled();

        await reason.setValue('Fue empate');
        await button(wrapper, 'Enviar')?.trigger('click');
        await flushPromises();

        const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/torneos/partidas/match-1/discutir');
        expect(JSON.parse(String(init.body))).toEqual({ report: 'abc123', reason: 'Fue empate' });
    });

    it('explains a disputed report to the reporter', () => {
        const wrapper = mountCard({
            report: matchReport({
                status: 'disputed',
                reported_by_name: 'John Doe',
                dispute_reason: 'Fue empate',
            }),
            actions: {
                can_report: false,
                can_correct: true,
                can_confirm: false,
                can_dispute: false,
            },
        });

        expect(wrapper.text()).toContain('Jane Doe lo discute: Fue empate');
    });

    it('tells the player the organization records results in admin only mode', () => {
        const wrapper = mountCard({
            result_reporting: 'admin_only',
            actions: {
                can_report: false,
                can_correct: false,
                can_confirm: false,
                can_dispute: false,
            },
        });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.text()).toContain('La organización registrará el resultado');
    });

    it('shows the official result once there is one', () => {
        const wrapper = mountCard({
            match: {
                ...playerMatch().match,
                result: 'draw',
                result_label: 'Empate',
                player_1_score: 15,
                player_2_score: 15,
            },
            actions: {
                can_report: false,
                can_correct: false,
                can_confirm: false,
                can_dispute: false,
            },
        });

        expect(wrapper.text()).toContain('Resultado: Empate (15-15)');
        expect(wrapper.find('form').exists()).toBe(false);
    });
});
