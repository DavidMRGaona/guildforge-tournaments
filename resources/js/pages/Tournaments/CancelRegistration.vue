<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import BaseButton from '@/components/ui/BaseButton.vue';
import { VENUE_TIMEZONE } from '@/utils/datetime';

// Why the page cannot cancel; null while it can
type CancelBlockedReason = 'already_cancelled' | 'tournament_started' | 'tournament_cancelled';

interface Props {
    participant: {
        id: string;
        status: string;
        is_guest: boolean;
        guest_name: string | null;
        guest_email: string | null;
    };
    tournament: {
        id: string;
        name: string;
        eventDate: string | null;
    };
    token: string;
    canCancel: boolean;
    cancelBlockedReason: CancelBlockedReason | null;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const displayName = computed(() => {
    return props.participant.guest_name || t('tournaments.cancellation.guest');
});

const formattedDate = computed(() => {
    if (!props.tournament.eventDate) {
        return null;
    }
    const date = new Date(props.tournament.eventDate);
    return date.toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
});

const isLoading = computed(() => false);

const blockedMessage = computed(() => {
    switch (props.cancelBlockedReason) {
        case 'tournament_started':
            return t('tournaments.cancellation.tournament_started');
        case 'tournament_cancelled':
            return t('tournaments.cancellation.tournament_cancelled');
        default:
            return t('tournaments.cancellation.already_cancelled');
    }
});

function handleCancel(): void {
    router.delete(`/torneos/cancelar/${props.token}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="mx-auto max-w-2xl px-4 py-12">
        <div class="rounded-xl bg-surface p-8 shadow-lg">
            <!-- Header -->
            <div class="mb-6 text-center">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-warning-light">
                    <TriangleAlert class="h-8 w-8 text-warning" aria-hidden="true" />
                </div>
                <h1 class="text-2xl font-bold text-base-primary">
                    {{ t('tournaments.cancellation.title') }}
                </h1>
            </div>

            <!-- Registration info -->
            <div class="mb-6 rounded-lg bg-surface-secondary p-4">
                <dl class="space-y-2">
                    <div class="flex justify-between">
                        <dt class="text-sm text-base-muted">
                            {{ t('tournaments.cancellation.participant') }}
                        </dt>
                        <dd class="text-sm font-medium text-base-primary">
                            {{ displayName }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-sm text-base-muted">
                            {{ t('tournaments.cancellation.tournament') }}
                        </dt>
                        <dd class="text-sm font-medium text-base-primary">
                            {{ tournament.name }}
                        </dd>
                    </div>
                    <div
                        v-if="formattedDate"
                        class="flex justify-between"
                    >
                        <dt class="text-sm text-base-muted">
                            {{ t('tournaments.cancellation.date') }}
                        </dt>
                        <dd class="text-sm font-medium text-base-primary">
                            {{ formattedDate }}
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Can cancel -->
            <template v-if="canCancel">
                <p class="mb-6 text-center text-base-secondary">
                    {{ t('tournaments.cancellation.confirm_message', { tournament: tournament.name }) }}
                </p>

                <div class="flex gap-4">
                    <!-- Styled as the core's secondary BaseButton, without a button inside the link -->
                    <Link
                        href="/torneos"
                        class="flex-1 inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md bg-muted px-4 py-2 text-base font-medium text-base-primary transition-colors hover:bg-base-secondary/20 focus:outline-none focus:ring-2 focus:ring-base-muted focus:ring-offset-2 focus:ring-offset-surface"
                    >
                        {{ $t('common.back') }}
                    </Link>
                    <BaseButton
                        variant="danger"
                        class="flex-1"
                        :loading="isLoading"
                        @click="handleCancel"
                    >
                        {{ t('tournaments.cancellation.confirm_button') }}
                    </BaseButton>
                </div>
            </template>

            <!-- Cannot cancel -->
            <template v-else>
                <div class="mb-6 rounded-lg bg-warning-light p-4 text-center">
                    <p class="text-sm text-warning-dark">
                        {{ blockedMessage }}
                    </p>
                </div>

                <!-- Styled as the core's primary BaseButton, without a button inside the link -->
                <Link
                    href="/torneos"
                    class="inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-base font-medium text-white transition-colors hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface"
                >
                    {{ t('tournaments.cancellation.view_tournaments') }}
                </Link>
            </template>
        </div>
    </div>
</template>
