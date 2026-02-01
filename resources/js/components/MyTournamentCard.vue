<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { UserTournament } from '../types/tournaments';
import { buildCardImageUrl } from '@/utils/cloudinary';

interface Props {
    tournament: UserTournament;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const tournamentImageUrl = computed(() => buildCardImageUrl(props.tournament.imagePublicId));

const statusColorClasses = computed(() => {
    const colorMap: Record<string, string> = {
        success: 'bg-success-light text-success',
        info: 'bg-info-light text-info',
        warning: 'bg-warning-light text-warning',
        gray: 'bg-muted text-base-secondary',
        danger: 'bg-error-light text-error',
        primary: 'bg-primary-light text-primary',
    };
    return colorMap[props.tournament.statusColor] || colorMap.gray;
});

const participantStatusColorClasses = computed(() => {
    const colorMap: Record<string, string> = {
        success: 'bg-success-light text-success',
        info: 'bg-info-light text-info',
        warning: 'bg-warning-light text-warning',
        gray: 'bg-muted text-base-secondary',
        danger: 'bg-error-light text-error',
    };
    return colorMap[props.tournament.participantStatusColor] || colorMap.gray;
});

const formattedDate = computed(() => {
    if (!props.tournament.startsAt) return null;
    return new Date(props.tournament.startsAt).toLocaleDateString(locale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
});

const formattedTime = computed(() => {
    if (!props.tournament.startsAt) return null;
    return new Date(props.tournament.startsAt).toLocaleTimeString(locale.value, {
        hour: '2-digit',
        minute: '2-digit',
    });
});

const positionText = computed(() => {
    if (props.tournament.position === null) return null;
    return t('tournaments.my_tournaments.position_of', {
        position: props.tournament.position,
        total: props.tournament.totalParticipants,
    });
});

const checkInDeadlineFormatted = computed(() => {
    if (!props.tournament.checkInDeadline) return null;
    return new Date(props.tournament.checkInDeadline).toLocaleTimeString(locale.value, {
        hour: '2-digit',
        minute: '2-digit',
    });
});

const tournamentUrl = computed(() => `/torneos/${props.tournament.slug}`);
const checkInUrl = computed(() => `/torneos/${props.tournament.slug}/check-in`);
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-lg border border-default bg-surface transition-all hover:border-primary-300 hover:shadow-md dark:hover:border-primary-600"
    >
        <div class="flex flex-col sm:flex-row">
            <!-- Image section -->
            <div class="relative h-32 w-full shrink-0 sm:h-auto sm:w-40">
                <img
                    v-if="tournamentImageUrl"
                    :src="tournamentImageUrl"
                    :alt="tournament.name"
                    loading="lazy"
                    class="h-full w-full object-cover"
                />
                <div
                    v-else
                    class="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary-500 to-stone-600 dark:from-primary-600 dark:to-stone-700"
                >
                    <svg
                        class="h-10 w-10 text-white/50"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m3.044-.001a6.726 6.726 0 0 0 2.749-1.35m0 0a6.772 6.772 0 0 1-2.697 3.423m0 0v.25M14.521 13.401a6.913 6.913 0 0 1-5.043.001M18.75 4.236c.982.143 1.954.317 2.916.52A6.003 6.003 0 0 1 16.27 9.728m2.48-5.492V2.721"
                        />
                    </svg>
                </div>
            </div>

            <!-- Content section -->
            <div class="flex flex-1 flex-col p-4">
                <!-- Header row: Title + badges -->
                <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="tournamentUrl"
                            class="block text-lg font-semibold text-base-primary hover:text-primary dark:hover:text-primary-400"
                        >
                            {{ tournament.name }}
                        </Link>
                        <p
                            v-if="tournament.eventName"
                            class="text-sm text-base-muted"
                        >
                            {{ tournament.eventName }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <span
                            :class="[
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                statusColorClasses,
                            ]"
                        >
                            {{ tournament.statusLabel }}
                        </span>
                        <span
                            :class="[
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                participantStatusColorClasses,
                            ]"
                        >
                            {{ tournament.participantStatusLabel }}
                        </span>
                    </div>
                </div>

                <!-- Date and time -->
                <div
                    v-if="formattedDate"
                    class="mb-3 flex items-center gap-1.5 text-sm text-base-muted"
                >
                    <svg
                        class="h-4 w-4 shrink-0"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                        />
                    </svg>
                    <span>{{ formattedDate }}</span>
                    <span v-if="formattedTime" class="text-base-muted">&middot;</span>
                    <span v-if="formattedTime">{{ formattedTime }}</span>
                </div>

                <!-- Stats row (position + points) - only for in progress or finished -->
                <div
                    v-if="tournament.isInProgress || tournament.isPast"
                    class="mb-3 flex flex-wrap items-center gap-4 text-sm"
                >
                    <!-- Position -->
                    <div v-if="positionText" class="flex items-center gap-1.5 text-base-secondary">
                        <svg
                            class="h-4 w-4 text-primary"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                            />
                        </svg>
                        <span>{{ positionText }}</span>
                    </div>

                    <!-- Points -->
                    <div class="flex items-center gap-1.5 text-base-secondary">
                        <svg
                            class="h-4 w-4 text-primary"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"
                            />
                        </svg>
                        <span>{{ tournament.points }} {{ t('tournaments.my_tournaments.points') }}</span>
                    </div>
                </div>

                <!-- Next match info - only for in progress tournaments -->
                <div
                    v-if="tournament.isInProgress && tournament.nextMatch"
                    class="mb-3 rounded-md bg-muted p-2.5"
                >
                    <div class="flex items-center gap-2 text-sm">
                        <svg
                            class="h-4 w-4 shrink-0 text-primary"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"
                            />
                        </svg>
                        <div class="flex flex-wrap items-center gap-x-2 text-base-secondary">
                            <span class="font-medium">{{ t('tournaments.my_tournaments.next_match') }}:</span>
                            <span>{{ t('tournaments.my_tournaments.round') }} {{ tournament.nextMatch.roundNumber }}</span>
                            <span v-if="tournament.nextMatch.isBye" class="text-primary dark:text-primary-400">
                                ({{ t('tournaments.my_tournaments.bye') }})
                            </span>
                            <template v-else-if="tournament.nextMatch.opponentName">
                                <span>{{ t('tournaments.my_tournaments.vs') }} {{ tournament.nextMatch.opponentName }}</span>
                                <span v-if="tournament.nextMatch.tableNumber" class="text-base-muted">
                                    &middot; {{ t('tournaments.my_tournaments.table') }} {{ tournament.nextMatch.tableNumber }}
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Actions row -->
                <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
                    <!-- Check-in button -->
                    <Link
                        v-if="tournament.canCheckIn"
                        :href="checkInUrl"
                        class="inline-flex items-center gap-1.5 rounded-md bg-green-600 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 dark:focus:ring-offset-stone-800"
                    >
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                        {{ t('tournaments.my_tournaments.check_in_now') }}
                        <span v-if="checkInDeadlineFormatted" class="text-green-200">
                            ({{ t('tournaments.my_tournaments.until') }} {{ checkInDeadlineFormatted }})
                        </span>
                    </Link>

                    <!-- View tournament link -->
                    <Link
                        :href="tournamentUrl"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
                    >
                        {{ t('tournaments.my_tournaments.view_tournament') }}
                        <svg
                            class="h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5l7 7-7 7"
                            />
                        </svg>
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
