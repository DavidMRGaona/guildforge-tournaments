<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { UserTournament } from '../../types/tournaments';

interface Props {
    tournament: UserTournament;
}

const props = defineProps<Props>();
const { t, locale } = useI18n();

const tournamentUrl = computed(() => `/torneos/${props.tournament.slug}`);

const statusColorClasses = computed(() => {
    const colorMap: Record<string, string> = {
        success: 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200',
        info: 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200',
        warning: 'bg-primary-100 text-primary-800 dark:bg-primary-900/50 dark:text-primary-200',
        gray: 'bg-stone-100 text-stone-800 dark:bg-stone-700 dark:text-stone-200',
        danger: 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
        primary: 'bg-primary-100 text-primary-800 dark:bg-primary-900/50 dark:text-primary-200',
    };
    return colorMap[props.tournament.statusColor] || colorMap.gray;
});

const formattedDate = computed(() => {
    if (!props.tournament.startsAt) return null;
    return new Date(props.tournament.startsAt).toLocaleDateString(locale.value, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
});

const positionText = computed(() => {
    if (props.tournament.position === null) return null;
    return t('tournaments.my_tournaments.position_of', {
        position: props.tournament.position,
        total: props.tournament.totalParticipants,
    });
});
</script>

<template>
    <Link
        :href="tournamentUrl"
        class="group block rounded-lg border border-default bg-surface p-4 transition-all hover:border-primary-300 hover:shadow-sm dark:hover:border-primary-600"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <!-- Left: Title and details -->
            <div class="min-w-0 flex-1">
                <h4
                    class="truncate text-base font-medium text-base-primary group-hover:text-primary dark:group-hover:text-primary-400"
                >
                    {{ tournament.name }}
                </h4>
                <p v-if="tournament.eventName" class="mt-0.5 text-sm text-primary dark:text-primary-400">
                    {{ tournament.eventName }}
                </p>

                <!-- Date -->
                <div
                    v-if="formattedDate"
                    class="mt-2 flex items-center gap-1.5 text-sm text-base-muted"
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
                </div>

                <!-- Position and points for in progress/past tournaments -->
                <div
                    v-if="tournament.isInProgress || tournament.isPast"
                    class="mt-2 flex flex-wrap items-center gap-3 text-sm text-base-muted"
                >
                    <span v-if="positionText" class="flex items-center gap-1">
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
                        {{ positionText }}
                    </span>
                    <span class="flex items-center gap-1">
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
                        {{ tournament.points }} {{ t('tournaments.my_tournaments.points') }}
                    </span>
                </div>
            </div>

            <!-- Right: Status badge -->
            <div class="flex items-center">
                <span
                    :class="[
                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                        statusColorClasses,
                    ]"
                >
                    {{ tournament.statusLabel }}
                </span>
            </div>
        </div>
    </Link>
</template>
