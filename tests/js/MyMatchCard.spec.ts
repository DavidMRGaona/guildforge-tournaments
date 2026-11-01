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

    it('sends an untouched yes-or-no stat as no', async () => {
        const wrapper = mountCard({
            stat_definitions: [
                {
                    key: 'painted',
                    name: 'Ejército pintado',
                    type: 'boolean',
                    min_value: null,
                    max_value: null,
                    per_player: true,
                    required: true,
                    description: null,
                },
            ],
        });

        await wrapper.findAll('input[type="radio"]')[1].setValue(true);
        await wrapper.find('input[name="player_1_stats.painted"]').setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const [, init] = fetchSpy.mock.calls[0] as [string, RequestInit];
        const body = JSON.parse(String(init.body)) as Record<string, unknown>;
        expect(body.player_1_stats).toEqual({ painted: true });
        expect(body.player_2_stats).toEqual({ painted: false });
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

    describe('why the player cannot report', () => {
        const closed = {
            can_report: false,
            can_correct: false,
            can_confirm: false,
            can_dispute: false,
        };

        it('tells a withdrawn or disqualified player the organization records it', () => {
            const wrapper = mountCard({
                result_reporting: 'players_trusted',
                closed_reason: 'not_playing',
                actions: closed,
            });

            expect(wrapper.text()).toContain(
                'Ya no juegas en este torneo: la organización registrará el resultado.'
            );
        });

        it('tells the player to wait for the round to start', () => {
            const wrapper = mountCard({ closed_reason: 'round_pending', actions: closed });

            expect(wrapper.text()).toContain(
                'Podrás informar del resultado cuando empiece la ronda.'
            );
            expect(wrapper.text()).not.toContain('La organización registrará');
        });
    });

    describe('a bye', () => {
        function bye(points: number): VueWrapper {
            return mountCard({
                match: {
                    ...playerMatch().match,
                    is_bye: true,
                    player_2_id: null,
                    player_2_name: null,
                },
                opponent_name: null,
                bye_points: points,
                actions: {
                    can_report: false,
                    can_correct: false,
                    can_confirm: false,
                    can_dispute: false,
                },
            });
        }

        it('says the points the scoring rules give it', () => {
            expect(bye(3).text()).toContain('Descansas en esta ronda: el bye te da 3 puntos.');
            expect(bye(1).text()).toContain('Descansas en esta ronda: el bye te da 1 punto.');
            expect(bye(2.5).text()).toContain('Descansas en esta ronda: el bye te da 2,5 puntos.');
        });

        it('says when it gives no points', () => {
            expect(bye(0).text()).toContain('Descansas en esta ronda: el bye no suma puntos.');
        });
    });

    it('shows an error about a field the form does not show', async () => {
        fetchSpy.mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Error',
                    errors: { 'player_1_stats.painted': ['Falta saber si está pintado'] },
                }),
                { status: 422, headers: { 'Content-Type': 'application/json' } }
            )
        );
        const wrapper = mountCard();

        await wrapper.findAll('input[type="radio"]')[0].setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const alert = wrapper.find('form [role="alert"]');
        expect(alert.exists()).toBe(true);
        expect(alert.text()).toBe('Falta saber si está pintado');
    });

    it('moves the focus to the first field with an error', async () => {
        fetchSpy.mockResolvedValue(
            new Response(
                JSON.stringify({
                    message: 'Error',
                    errors: {
                        'player_2_stats.vp': ['Demasiados puntos'],
                        player_1_score: ['No puede ser negativa'],
                    },
                }),
                { status: 422, headers: { 'Content-Type': 'application/json' } }
            )
        );
        const wrapper = mount(MyMatchCard, {
            props: { playerMatch: playerMatch() },
            attachTo: document.body,
        });

        await wrapper.findAll('input[type="radio"]')[0].setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.find('input[name="player_1_score"]').element);
    });

    describe('disputing', () => {
        const rivalActions = {
            can_report: false,
            can_correct: false,
            can_confirm: true,
            can_dispute: true,
        };

        it('moves the focus to the reason', async () => {
            const wrapper = mount(MyMatchCard, {
                props: {
                    playerMatch: playerMatch({ report: matchReport(), actions: rivalActions }),
                },
                attachTo: document.body,
            });

            await button(wrapper, 'Discutir')?.trigger('click');
            await flushPromises();

            expect(document.activeElement).toBe(wrapper.find('textarea[name="reason"]').element);
        });

        it('announces a missing reason apart from the help', async () => {
            const wrapper = mountCard({ report: matchReport(), actions: rivalActions });

            await button(wrapper, 'Discutir')?.trigger('click');
            const help = wrapper.find('textarea[name="reason"] + p');
            expect(help.classes()).toContain('text-base-muted');
            expect(wrapper.find('[role="alert"]').exists()).toBe(false);

            await button(wrapper, 'Enviar')?.trigger('click');
            await flushPromises();

            const alert = wrapper.find('[role="alert"]');
            expect(alert.text()).toBe('Escribe el motivo para que la organización lo revise.');
            expect(alert.classes()).toContain('text-error');
            expect(alert.classes()).not.toContain('text-base-muted');
            const describedBy =
                wrapper.find('textarea[name="reason"]').attributes('aria-describedby') ?? '';
            expect(describedBy.split(' ')).toContain(alert.attributes('id'));
            expect(wrapper.findAll('.text-base-muted.text-error')).toHaveLength(0);
        });
    });

    describe('after the tournament stops asking for confirmation', () => {
        const leftover = {
            result_reporting: 'players_trusted' as const,
            report: matchReport(),
            actions: {
                can_report: true,
                can_correct: false,
                can_confirm: true,
                can_dispute: false,
            },
        };

        it('lets the rival confirm the report left or send their own', async () => {
            const wrapper = mountCard(leftover);

            expect(wrapper.text()).toContain('Jane Doe ha informado: Gana Jane Doe (10-20)');
            expect(button(wrapper, 'Confirmar')).toBeDefined();
            expect(button(wrapper, 'Discutir')).toBeUndefined();
            expect(wrapper.find('form').exists()).toBe(false);

            await button(wrapper, 'Enviar otro resultado')?.trigger('click');

            expect(wrapper.find('form').exists()).toBe(true);
        });

        it('keeps the other actions still while one is sent', async () => {
            fetchSpy.mockReturnValue(new Promise(() => undefined));
            const wrapper = mountCard(leftover);

            await button(wrapper, 'Confirmar')?.trigger('click');

            expect(button(wrapper, 'Enviar otro resultado')?.attributes('disabled')).toBeDefined();
        });

        it('lets the rival who disputed it send their own result', async () => {
            const wrapper = mountCard({
                ...leftover,
                report: matchReport({ status: 'disputed', dispute_reason: 'Fue empate' }),
                actions: {
                    can_report: true,
                    can_correct: false,
                    can_confirm: false,
                    can_dispute: false,
                },
            });

            expect(wrapper.text()).toContain('Has discutido el resultado');
            await button(wrapper, 'Enviar otro resultado')?.trigger('click');

            expect(wrapper.find('form').exists()).toBe(true);
        });

        it('asks the reporter to send their report again', () => {
            const wrapper = mountCard({
                result_reporting: 'players_trusted',
                report: matchReport({ reported_by_name: 'John Doe', reported_by_id: 'user-1' }),
                actions: {
                    can_report: false,
                    can_correct: true,
                    can_confirm: false,
                    can_dispute: false,
                },
            });

            expect(wrapper.text()).toContain(
                'Lo enviaste cuando hacía falta que Jane Doe lo confirmara: Gana Jane Doe (10-20). Corrígelo o envíalo de nuevo para que cuente.'
            );
        });
    });

    it('reloads the standings with the match after a result is sent', async () => {
        const wrapper = mountCard({ result_reporting: 'players_trusted' });

        await wrapper.findAll('input[type="radio"]')[0].setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(router.reload).toHaveBeenCalledWith({
            only: ['myMatch', 'currentRound', 'standings'],
        });
    });
});
