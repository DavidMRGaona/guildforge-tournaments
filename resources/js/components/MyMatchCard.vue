<script setup lang="ts">
import { computed, nextTick, reactive, ref, useId } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useNotifications } from '@/composables/useNotifications';
import { useVerificationGate } from '@/composables/useVerificationGate';
import { csrfHeaders, isCsrfFailure } from '@/utils/csrf';
import type {
    MatchResult,
    PlayerMatch,
    ReportableResult,
    StatDefinition,
    StatValue,
} from '../types/tournaments';

const props = defineProps<{
    playerMatch: PlayerMatch;
}>();

const { t, locale } = useI18n();
const { error: notifyError, success: notifySuccess } = useNotifications();
const gate = useVerificationGate();
const id = useId();

type Side = 1 | 2;
// Vue's v-model gives number inputs a number, or '' when empty
type StatInput = string | number | boolean;

const match = computed(() => props.playerMatch.match);
const report = computed(() => props.playerMatch.report);
const actions = computed(() => props.playerMatch.actions);
const names = computed<Record<Side, string>>(() => ({
    1: match.value.player_1_name,
    2: match.value.player_2_name ?? '',
}));
const opponent = computed(() => props.playerMatch.opponent_name ?? '');
const hasOfficialResult = computed(() => match.value.result !== 'not_played');
const sides: Side[] = [1, 2];

// The reporter reopens the form to correct a report waiting for confirmation; without
// confirmation, the rival of a report left from before opens it to send their own result
const correcting = ref(false);
const showForm = computed(
    () =>
        (actions.value.can_report && (report.value === null || correcting.value)) ||
        (actions.value.can_correct && correcting.value)
);
const isTrusted = computed(() => props.playerMatch.result_reporting === 'players_trusted');

const form = reactive<{
    result: ReportableResult | '';
    scores: Record<Side, string | number>;
    stats: Record<Side, Record<string, StatInput>>;
}>({ result: '', scores: { 1: '', 2: '' }, stats: { 1: {}, 2: {} } });
const errors = ref<Record<string, string>>({});
const processing = ref(false);
const formRef = ref<HTMLFormElement | null>(null);

const disputing = ref(false);
const disputeReason = ref('');
const disputeError = ref('');
const reasonRef = ref<HTMLTextAreaElement | null>(null);
const reasonError = computed(() => errors.value.reason ?? disputeError.value);

/** The fields the form shows; an error about any other (a stat removed meanwhile) goes on top */
const formFields = computed(() => {
    const fields = new Set(['result']);
    for (const side of sides) {
        fields.add(`player_${side}_score`);
        for (const stat of props.playerMatch.stat_definitions) {
            fields.add(`player_${side}_stats.${stat.key}`);
        }
    }

    return fields;
});
const otherErrors = computed(() =>
    Object.entries(errors.value)
        .filter(([field]) => !formFields.value.has(field))
        .map(([, message]) => message)
);

const byeText = computed(() => {
    const points = props.playerMatch.bye_points ?? 0;
    if (points === 0) {
        return t('tournaments.my_match.bye_no_points');
    }

    return t('tournaments.my_match.bye', points, {
        named: { points: new Intl.NumberFormat(locale.value).format(points) },
    });
});

const closedText = computed(() => {
    switch (props.playerMatch.closed_reason) {
        case 'not_playing':
            return t('tournaments.my_match.not_playing');
        case 'round_pending':
            return t('tournaments.my_match.round_pending');
        default:
            return t('tournaments.my_match.admin_only');
    }
});

const resultOptions = computed<{ value: ReportableResult; label: string }[]>(() => [
    { value: 'player_one_win', label: t('tournaments.my_match.win', { name: names.value[1] }) },
    { value: 'draw', label: t('tournaments.my_match.draw') },
    { value: 'player_two_win', label: t('tournaments.my_match.win', { name: names.value[2] }) },
]);

const hint = computed<string>(() => {
    if (props.playerMatch.result_reporting === 'players_trusted') {
        return t('tournaments.my_match.trusted_hint');
    }

    return props.playerMatch.opponent_is_guest
        ? t('tournaments.my_match.guest_hint', { name: opponent.value })
        : t('tournaments.my_match.confirmation_hint', { name: opponent.value });
});

/** "Gana Ana (20-15)" or "Empate (15-15)" */
function summary(result: MatchResult, score1: number | null, score2: number | null): string {
    let text: string;
    if (result === 'player_one_win') {
        text = t('tournaments.my_match.win', { name: names.value[1] });
    } else if (result === 'player_two_win') {
        text = t('tournaments.my_match.win', { name: names.value[2] });
    } else if (result === 'draw') {
        text = t('tournaments.my_match.draw');
    } else {
        text = match.value.result_label;
    }

    return score1 !== null && score2 !== null ? `${text} (${score1}-${score2})` : text;
}

const reportSummary = computed(() =>
    report.value === null
        ? ''
        : summary(report.value.result, report.value.player_1_score, report.value.player_2_score)
);

function fieldId(field: string): string {
    return `${id}-${field.replace(/\./g, '-')}`;
}

function errorId(field: string): string | undefined {
    return errors.value[field] !== undefined ? `${fieldId(field)}-error` : undefined;
}

async function startDisputing(): Promise<void> {
    disputing.value = true;
    await nextTick();
    reasonRef.value?.focus();
}

/** After a refused send, the first field with an error, in the order the form shows them */
async function focusFirstError(): Promise<void> {
    await nextTick();
    formRef.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
}

function startCorrecting(): void {
    const current = report.value;
    if (
        current !== null &&
        (current.result === 'player_one_win' ||
            current.result === 'player_two_win' ||
            current.result === 'draw')
    ) {
        form.result = current.result;
        form.scores = {
            1: current.player_1_score?.toString() ?? '',
            2: current.player_2_score?.toString() ?? '',
        };
        form.stats = {
            1: toInputs(current.player_1_stats),
            2: toInputs(current.player_2_stats),
        };
    }
    correcting.value = true;
}

function toInputs(stats: Record<string, StatValue> | null): Record<string, StatInput> {
    const inputs: Record<string, StatInput> = {};
    for (const [key, value] of Object.entries(stats ?? {})) {
        inputs[key] = value;
    }

    return inputs;
}

function score(value: string | number): number | null {
    return typeof value === 'number' ? value : value.trim() === '' ? null : Number(value);
}

function statsPayload(side: Side): Record<string, StatValue> | null {
    const values: Record<string, StatValue> = {};
    for (const stat of props.playerMatch.stat_definitions) {
        const input = form.stats[side][stat.key];
        if (stat.type === 'boolean') {
            // An untouched box says no: a required yes-or-no stat is answered either way
            values[stat.key] = input === true;
        } else if (typeof input === 'number' || typeof input === 'string') {
            const value = score(input);
            if (value !== null) {
                values[stat.key] = value;
            }
        }
    }

    return Object.keys(values).length > 0 ? values : null;
}

/** Sends one action; true when the server accepted it. */
async function post(
    action: 'resultado' | 'confirmar' | 'discutir',
    body: object
): Promise<boolean> {
    processing.value = true;
    errors.value = {};

    try {
        const response = await fetch(`/torneos/partidas/${match.value.id}/${action}`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify(body),
        });

        if (isCsrfFailure(response)) {
            notifyError(t('tournaments.public.session_expired'));
            return false;
        }

        if (gate.isVerificationRequired(response)) {
            gate.goToNotice();
            return false;
        }

        const data = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (response.ok) {
            notifySuccess(data.message ?? '');
            correcting.value = false;
            disputing.value = false;
            // A result that counts at once changes the standings too
            router.reload({ only: ['myMatch', 'currentRound', 'standings'] });
            return true;
        }

        if (response.status === 422 && data.errors !== undefined) {
            errors.value = Object.fromEntries(
                Object.entries(data.errors).map(([field, messages]) => [field, messages[0] ?? ''])
            );
            // Disabled fields take no focus: release them first
            processing.value = false;
            if (action === 'resultado') {
                await focusFirstError();
            } else if (action === 'discutir') {
                reasonRef.value?.focus();
            }
            return false;
        }

        notifyError(data.message ?? t('tournaments.my_match.error'));
        // The match changed meanwhile (confirmed, corrected, round closed): show it as it is now
        if (response.status === 409) {
            router.reload({ only: ['myMatch', 'currentRound'] });
        }

        return false;
    } catch {
        notifyError(t('tournaments.my_match.error'));
        return false;
    } finally {
        processing.value = false;
    }
}

async function submitReport(): Promise<void> {
    const body: Record<string, unknown> = {
        result: form.result,
        player_1_score: score(form.scores[1]),
        player_2_score: score(form.scores[2]),
    };
    if (props.playerMatch.stat_definitions.length > 0) {
        body.player_1_stats = statsPayload(1);
        body.player_2_stats = statsPayload(2);
    }

    await post('resultado', body);
}

async function confirmReport(): Promise<void> {
    if (report.value !== null) {
        await post('confirmar', { report: report.value.fingerprint });
    }
}

async function sendDispute(): Promise<void> {
    disputeError.value = '';
    if (disputeReason.value.trim() === '') {
        disputeError.value = t('tournaments.my_match.reason_required');
        reasonRef.value?.focus();
        return;
    }

    if (report.value !== null) {
        await post('discutir', {
            report: report.value.fingerprint,
            reason: disputeReason.value.trim(),
        });
    }
}

function statStep(stat: StatDefinition): string {
    return stat.type === 'float' ? '0.01' : '1';
}

const inputClass =
    'w-full rounded-md border border-default px-3 py-2 shadow-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:bg-base-secondary/10';
</script>

<template>
    <section
        class="mb-6 rounded-lg border border-default bg-surface p-4"
        :aria-labelledby="`${id}-title`"
    >
        <h2 :id="`${id}-title`" class="font-semibold text-base-primary">
            {{ t('tournaments.my_match.title') }}
        </h2>
        <p class="mt-1 text-sm text-base-secondary">
            <template v-if="match.table_number !== null">
                {{ t('tournaments.my_match.table', { number: match.table_number }) }} ·
            </template>
            <template v-if="playerMatch.opponent_name !== null">
                {{ t('tournaments.my_match.against', { name: opponent }) }}
            </template>
        </p>

        <p v-if="match.is_bye" class="mt-3 text-sm text-base-secondary">
            {{ byeText }}
        </p>

        <p v-else-if="hasOfficialResult" class="mt-3 text-sm font-medium text-base-primary">
            {{
                t('tournaments.my_match.official', {
                    result: summary(match.result, match.player_1_score, match.player_2_score),
                })
            }}
        </p>

        <template v-else-if="report !== null && !showForm">
            <!-- The opponent: confirm or dispute this version -->
            <div v-if="actions.can_confirm || actions.can_dispute" class="mt-3 space-y-3">
                <p class="text-sm text-base-primary">
                    {{
                        t('tournaments.my_match.reported_by', {
                            name: report.reported_by_name,
                            result: reportSummary,
                        })
                    }}
                </p>
                <div v-if="!disputing" class="flex flex-col gap-2 sm:flex-row">
                    <BaseButton
                        v-if="actions.can_confirm"
                        size="sm"
                        :loading="processing"
                        @click="confirmReport"
                    >
                        {{ t('tournaments.my_match.confirm') }}
                    </BaseButton>
                    <BaseButton
                        v-if="actions.can_dispute"
                        variant="secondary"
                        size="sm"
                        :disabled="processing"
                        @click="startDisputing"
                    >
                        {{ t('tournaments.my_match.dispute') }}
                    </BaseButton>
                    <!-- Without confirmation, sending one's own result replaces the report -->
                    <BaseButton
                        v-if="actions.can_report"
                        variant="secondary"
                        size="sm"
                        :disabled="processing"
                        @click="correcting = true"
                    >
                        {{ t('tournaments.my_match.send_another') }}
                    </BaseButton>
                </div>
                <div v-else class="space-y-2">
                    <label
                        :for="`${id}-reason`"
                        class="block text-sm font-medium text-base-secondary"
                    >
                        {{ t('tournaments.my_match.reason') }}
                        <span class="text-error" aria-hidden="true">*</span>
                    </label>
                    <textarea
                        :id="`${id}-reason`"
                        ref="reasonRef"
                        v-model="disputeReason"
                        name="reason"
                        required
                        maxlength="500"
                        rows="3"
                        :aria-invalid="reasonError !== ''"
                        :aria-describedby="
                            reasonError !== ''
                                ? `${id}-reason-help ${id}-reason-error`
                                : `${id}-reason-help`
                        "
                        :class="inputClass"
                    />
                    <p :id="`${id}-reason-help`" class="text-sm text-base-muted">
                        {{ t('tournaments.my_match.reason_help') }}
                    </p>
                    <p
                        v-if="reasonError !== ''"
                        :id="`${id}-reason-error`"
                        class="text-sm text-error"
                        role="alert"
                    >
                        {{ reasonError }}
                    </p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <BaseButton size="sm" :loading="processing" @click="sendDispute">
                            {{ t('tournaments.my_match.send') }}
                        </BaseButton>
                        <BaseButton
                            variant="ghost"
                            size="sm"
                            :disabled="processing"
                            @click="disputing = false"
                        >
                            {{ t('tournaments.my_match.cancel') }}
                        </BaseButton>
                    </div>
                </div>
            </div>

            <!-- The reporter: waiting, or disputed, and they may still correct it -->
            <div v-else-if="actions.can_correct" class="mt-3 space-y-3">
                <p class="text-sm text-base-primary">
                    <template v-if="report.status === 'disputed'">
                        {{
                            t('tournaments.my_match.disputed_by', {
                                name: opponent,
                                reason: report.dispute_reason ?? '',
                            })
                        }}
                    </template>
                    <template v-else>
                        {{
                            t(
                                isTrusted
                                    ? 'tournaments.my_match.left_from_confirmation'
                                    : 'tournaments.my_match.waiting_for',
                                { name: opponent, result: reportSummary }
                            )
                        }}
                    </template>
                </p>
                <BaseButton
                    variant="secondary"
                    size="sm"
                    :disabled="processing"
                    @click="startCorrecting"
                >
                    {{ t('tournaments.my_match.correct') }}
                </BaseButton>
            </div>

            <p v-else-if="report.status === 'disputed'" class="mt-3 text-sm text-base-secondary">
                {{ t('tournaments.my_match.you_disputed') }}
            </p>
            <p v-else class="mt-3 text-sm text-base-secondary">
                {{
                    t('tournaments.my_match.reported_by', {
                        name: report.reported_by_name,
                        result: reportSummary,
                    })
                }}
            </p>
            <!-- A dispute left from when the tournament asked for confirmation: send one's own -->
            <BaseButton
                v-if="actions.can_report && !actions.can_confirm && !actions.can_dispute"
                class="mt-3"
                variant="secondary"
                size="sm"
                :disabled="processing"
                @click="correcting = true"
            >
                {{ t('tournaments.my_match.send_another') }}
            </BaseButton>
        </template>

        <form
            v-else-if="showForm"
            ref="formRef"
            class="mt-3 space-y-4"
            novalidate
            @submit.prevent="submitReport"
        >
            <fieldset :aria-describedby="errorId('result')">
                <legend class="mb-2 text-sm font-medium text-base-secondary">
                    {{ t('tournaments.my_match.result') }}
                </legend>
                <div class="flex flex-col gap-2 sm:flex-row sm:gap-4">
                    <div
                        v-for="option in resultOptions"
                        :key="option.value"
                        class="flex items-center gap-2"
                    >
                        <input
                            :id="fieldId(`result-${option.value}`)"
                            v-model="form.result"
                            type="radio"
                            name="result"
                            :value="option.value"
                            required
                            :disabled="processing"
                            :aria-invalid="errors.result !== undefined"
                            class="h-4 w-4 border-strong text-primary focus:ring-primary-500"
                        />
                        <label
                            :for="fieldId(`result-${option.value}`)"
                            class="text-sm text-base-primary"
                        >
                            {{ option.label }}
                        </label>
                    </div>
                </div>
                <p
                    v-if="errors.result !== undefined"
                    :id="errorId('result')"
                    class="mt-1 text-sm text-error"
                    role="alert"
                >
                    {{ errors.result }}
                </p>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <div v-for="side in sides" :key="side" class="space-y-3">
                    <div>
                        <label
                            :for="fieldId(`player_${side}_score`)"
                            class="mb-1 block text-sm font-medium text-base-secondary"
                        >
                            {{ t('tournaments.my_match.score_of', { name: names[side] }) }}
                            <span class="font-normal text-base-muted"
                                >({{ t('tournaments.my_match.optional') }})</span
                            >
                        </label>
                        <input
                            :id="fieldId(`player_${side}_score`)"
                            v-model="form.scores[side]"
                            :name="`player_${side}_score`"
                            type="number"
                            inputmode="numeric"
                            min="0"
                            step="1"
                            :disabled="processing"
                            :aria-invalid="errors[`player_${side}_score`] !== undefined"
                            :aria-describedby="errorId(`player_${side}_score`)"
                            :class="inputClass"
                        />
                        <p
                            v-if="errors[`player_${side}_score`] !== undefined"
                            :id="errorId(`player_${side}_score`)"
                            class="mt-1 text-sm text-error"
                            role="alert"
                        >
                            {{ errors[`player_${side}_score`] }}
                        </p>
                    </div>

                    <fieldset v-if="playerMatch.stat_definitions.length > 0" class="space-y-3">
                        <legend class="text-sm font-medium text-base-secondary">
                            {{ t('tournaments.my_match.stats_of', { name: names[side] }) }}
                        </legend>
                        <div v-for="stat in playerMatch.stat_definitions" :key="stat.key">
                            <template v-if="stat.type === 'boolean'">
                                <div class="flex items-center gap-2">
                                    <input
                                        :id="fieldId(`player_${side}_stats.${stat.key}`)"
                                        v-model="form.stats[side][stat.key]"
                                        :name="`player_${side}_stats.${stat.key}`"
                                        type="checkbox"
                                        :disabled="processing"
                                        :aria-invalid="
                                            errors[`player_${side}_stats.${stat.key}`] !== undefined
                                        "
                                        :aria-describedby="
                                            errorId(`player_${side}_stats.${stat.key}`)
                                        "
                                        class="h-4 w-4 rounded border-strong text-primary focus:ring-primary-500"
                                    />
                                    <label
                                        :for="fieldId(`player_${side}_stats.${stat.key}`)"
                                        class="text-sm text-base-primary"
                                    >
                                        {{ stat.name }}
                                    </label>
                                </div>
                            </template>
                            <template v-else>
                                <label
                                    :for="fieldId(`player_${side}_stats.${stat.key}`)"
                                    class="mb-1 block text-sm text-base-secondary"
                                >
                                    {{ stat.name }}
                                    <span v-if="stat.required" class="text-error" aria-hidden="true"
                                        >*</span
                                    >
                                </label>
                                <input
                                    :id="fieldId(`player_${side}_stats.${stat.key}`)"
                                    v-model="form.stats[side][stat.key]"
                                    :name="`player_${side}_stats.${stat.key}`"
                                    type="number"
                                    :step="statStep(stat)"
                                    :min="stat.min_value ?? undefined"
                                    :max="stat.max_value ?? undefined"
                                    :required="stat.required"
                                    :disabled="processing"
                                    :aria-invalid="
                                        errors[`player_${side}_stats.${stat.key}`] !== undefined
                                    "
                                    :aria-describedby="errorId(`player_${side}_stats.${stat.key}`)"
                                    :class="inputClass"
                                />
                            </template>
                            <p
                                v-if="errors[`player_${side}_stats.${stat.key}`] !== undefined"
                                :id="errorId(`player_${side}_stats.${stat.key}`)"
                                class="mt-1 text-sm text-error"
                                role="alert"
                            >
                                {{ errors[`player_${side}_stats.${stat.key}`] }}
                            </p>
                        </div>
                    </fieldset>
                </div>
            </div>

            <!-- An error about a field the form does not show, e.g. a stat removed meanwhile -->
            <div v-if="otherErrors.length > 0" class="space-y-1 text-sm text-error" role="alert">
                <p v-for="message in otherErrors" :key="message">{{ message }}</p>
            </div>

            <p class="text-sm text-base-muted">{{ hint }}</p>

            <div class="flex flex-col gap-2 sm:flex-row">
                <BaseButton type="submit" size="sm" :loading="processing">
                    {{ t('tournaments.my_match.submit') }}
                </BaseButton>
                <BaseButton
                    v-if="correcting"
                    variant="ghost"
                    size="sm"
                    :disabled="processing"
                    @click="correcting = false"
                >
                    {{ t('tournaments.my_match.cancel') }}
                </BaseButton>
            </div>
        </form>

        <p v-else class="mt-3 text-sm text-base-secondary">
            {{ closedText }}
        </p>
    </section>
</template>
