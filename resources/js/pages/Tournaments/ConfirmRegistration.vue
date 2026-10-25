<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';

// What the link can still do: confirm (pending), nothing more (already_confirmed) or nothing at all (invalid)
type ConfirmationState = 'pending' | 'already_confirmed' | 'invalid';

interface Props {
    confirmation: {
        state: ConfirmationState;
        tournament_name: string | null;
        tournament_slug: string | null;
        guest_name: string | null;
        expires_at: string | null;
        seat_held: boolean;
    };
    token: string;
    // What pressing the button just did, already translated by the server
    result: {
        outcome: string;
        message: string;
    } | null;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const isSubmitting = ref(false);

const confirmUrl = computed(() => `/torneos/confirmar/${props.token}`);

const backHref = computed(() =>
    props.confirmation.tournament_slug
        ? `/torneos/${props.confirmation.tournament_slug}`
        : '/torneos'
);

const isSuccess = computed((): boolean =>
    props.result
        ? ['confirmed', 'already_confirmed'].includes(props.result.outcome)
        : props.confirmation.state === 'already_confirmed'
);

const showForm = computed(
    (): boolean => props.confirmation.state === 'pending' && props.result === null
);

const formattedDeadline = computed((): string | null => {
    if (!props.confirmation.expires_at) {
        return null;
    }
    return new Date(props.confirmation.expires_at).toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

const seatNotice = computed((): string => {
    if (props.confirmation.seat_held && formattedDeadline.value) {
        return t('tournaments.confirmation.deadline', { date: formattedDeadline.value });
    }
    return t('tournaments.confirmation.wait_notice');
});

const stateMessage = computed((): string => {
    if (props.result) {
        return props.result.message;
    }
    return props.confirmation.state === 'already_confirmed'
        ? t('tournaments.confirmation.already_confirmed')
        : t('tournaments.confirmation.invalid');
});

// Only the button confirms: opening the link (as mail scanners do) changes nothing
function handleConfirm(): void {
    router.post(
        confirmUrl.value,
        {},
        {
            onStart: () => {
                isSubmitting.value = true;
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        }
    );
}
</script>

<template>
    <div class="mx-auto max-w-2xl px-4 py-12">
        <div class="rounded-xl bg-surface p-8 shadow-lg">
            <h1 class="mb-6 text-center text-2xl font-bold text-base-primary">
                {{ t('tournaments.confirmation.title') }}
            </h1>

            <div
                v-if="confirmation.tournament_name"
                class="mb-6 rounded-lg bg-surface-secondary p-4"
            >
                <dl class="space-y-2">
                    <div v-if="confirmation.guest_name" class="flex justify-between gap-4">
                        <dt class="text-sm text-base-muted">
                            {{ t('tournaments.confirmation.participant') }}
                        </dt>
                        <dd class="text-right text-sm font-medium text-base-primary">
                            {{ confirmation.guest_name }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm text-base-muted">
                            {{ t('tournaments.confirmation.tournament') }}
                        </dt>
                        <dd class="text-right text-sm font-medium text-base-primary">
                            {{ confirmation.tournament_name }}
                        </dd>
                    </div>
                </dl>
            </div>

            <form
                v-if="showForm"
                method="post"
                :action="confirmUrl"
                @submit.prevent="handleConfirm"
            >
                <p class="mb-6 text-center text-base-secondary">
                    {{ seatNotice }}
                </p>

                <BaseButton type="submit" variant="primary" class="w-full" :loading="isSubmitting">
                    {{ t('tournaments.confirmation.confirm_button') }}
                </BaseButton>
            </form>

            <template v-else>
                <div
                    :role="isSuccess ? 'status' : 'alert'"
                    class="mb-6 rounded-lg p-4 text-center"
                    :class="isSuccess ? 'bg-success-light' : 'bg-warning-light'"
                >
                    <p class="text-sm" :class="isSuccess ? 'text-success' : 'text-warning-dark'">
                        {{ stateMessage }}
                    </p>
                </div>

                <!-- Styled as the core's primary BaseButton, without a button inside the link -->
                <Link
                    :href="backHref"
                    class="inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-base font-medium text-white transition-colors hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface"
                >
                    {{
                        confirmation.tournament_slug
                            ? t('tournaments.confirmation.view_tournament')
                            : t('tournaments.confirmation.view_tournaments')
                    }}
                </Link>
            </template>
        </div>
    </div>
</template>
