<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentRoundsProps, Match } from '../../types/tournaments';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { useSeo } from '@/composables/useSeo';
import { useTournaments } from '../../composables/useTournaments';

const props = defineProps<TournamentRoundsProps>();

const { t } = useI18n();
const { getMatchResultColor } = useTournaments();

useSeo({
    title: `${t('tournaments.public.rounds')} - ${props.tournament.name}`,
    description: t('tournaments.public.rounds_description', { name: props.tournament.name }),
    type: 'article',
    canonical: `/torneos/${props.tournament.slug}/rondas`,
});

// Track which rounds are expanded
const expandedRounds = ref<Set<string>>(new Set());

// Auto-expand the current round (in_progress)
const currentRound = computed(() => {
    return props.rounds.find(r => r.round.status === 'in_progress');
});

if (currentRound.value) {
    expandedRounds.value.add(currentRound.value.round.id);
}

const toggleRound = (roundId: string): void => {
    if (expandedRounds.value.has(roundId)) {
        expandedRounds.value.delete(roundId);
    } else {
        expandedRounds.value.add(roundId);
    }
};

const isExpanded = (roundId: string): boolean => {
    return expandedRounds.value.has(roundId);
};

const defaultBadgeClass = 'bg-muted text-base-secondary';

const getResultBadgeClass = (match: Match): string => {
    const color = getMatchResultColor(match.result);
    const colorClasses: Record<string, string> = {
        gray: defaultBadgeClass,
        green: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
        yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
        red: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400',
        purple: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    };
    return colorClasses[color] ?? defaultBadgeClass;
};

const sortedRounds = computed(() => {
    return [...props.rounds].sort((a, b) => b.round.round_number - a.round.round_number);
});
</script>

<template>
    <DefaultLayout>
        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Back link -->
            <div class="mb-6">
                <Link
                    :href="`/torneos/${tournament.slug}`"
                    class="inline-flex items-center text-sm text-base-muted hover:text-base-secondary focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-stone-900"
                >
                    <svg
                        class="mr-1 h-4 w-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 19l-7-7 7-7"
                        />
                    </svg>
                    {{ t('common.back') }}
                </Link>
            </div>

            <div class="overflow-hidden rounded-lg bg-surface shadow dark:shadow-stone-900/50">
                <div class="border-b border-default p-6">
                    <h1 class="text-2xl font-bold text-base-primary sm:text-3xl">
                        {{ t('tournaments.public.rounds') }}
                    </h1>
                    <p class="mt-1 text-base-muted">
                        {{ tournament.name }}
                    </p>
                </div>

                <div class="p-6">
                    <div v-if="rounds.length === 0" class="py-8 text-center text-base-muted">
                        {{ t('tournaments.public.no_rounds') }}
                    </div>

                    <!-- Rounds Accordion -->
                    <div v-else class="space-y-4">
                        <div
                            v-for="roundData in sortedRounds"
                            :key="roundData.round.id"
                            class="overflow-hidden rounded-lg border border-default"
                        >
                            <!-- Round Header (clickable) -->
                            <button
                                type="button"
                                class="flex w-full items-center justify-between p-4 text-left hover:bg-muted"
                                :class="{
                                    'bg-blue-50 dark:bg-blue-900/20': roundData.round.status === 'in_progress',
                                }"
                                @click="toggleRound(roundData.round.id)"
                            >
                                <div class="flex items-center gap-3">
                                    <span class="text-lg font-semibold text-base-primary">
                                        {{ t('tournaments.public.round_number', { number: roundData.round.round_number }) }}
                                    </span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="{
                                            'bg-muted text-base-secondary': roundData.round.status === 'pending',
                                            'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': roundData.round.status === 'in_progress',
                                            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400': roundData.round.status === 'finished',
                                        }"
                                    >
                                        {{ roundData.round.status_label }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-4">
                                    <span class="text-sm text-base-muted">
                                        {{ roundData.round.completed_match_count }} / {{ roundData.round.match_count }}
                                        {{ t('tournaments.public.matches_label') }}
                                    </span>
                                    <svg
                                        class="h-5 w-5 text-stone-400 transition-transform"
                                        :class="{ 'rotate-180': isExpanded(roundData.round.id) }"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                        aria-hidden="true"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M19 9l-7 7-7-7"
                                        />
                                    </svg>
                                </div>
                            </button>

                            <!-- Round Matches (collapsible) -->
                            <div
                                v-show="isExpanded(roundData.round.id)"
                                class="border-t border-default"
                            >
                                <div v-if="roundData.matches.length === 0" class="p-4 text-center text-base-muted">
                                    {{ t('tournaments.public.no_matches') }}
                                </div>

                                <div v-else class="divide-y divide-stone-100 dark:divide-stone-800">
                                    <div
                                        v-for="match in roundData.matches"
                                        :key="match.id"
                                        class="flex items-center justify-between p-4"
                                    >
                                        <!-- Match Info -->
                                        <div class="flex flex-1 items-center gap-4">
                                            <!-- Table Number -->
                                            <div
                                                v-if="match.table_number"
                                                class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded bg-muted text-sm font-medium text-base-secondary"
                                            >
                                                {{ match.table_number }}
                                            </div>

                                            <!-- Players -->
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="font-medium text-base-primary"
                                                        :class="{
                                                            'text-green-600 dark:text-green-400': match.result === 'player1_win',
                                                        }"
                                                    >
                                                        {{ match.player_1_name }}
                                                    </span>
                                                    <span
                                                        v-if="match.player_1_score !== null"
                                                        class="rounded bg-muted px-1.5 py-0.5 text-sm font-semibold text-base-secondary"
                                                    >
                                                        {{ match.player_1_score }}
                                                    </span>
                                                </div>

                                                <div v-if="match.player_2_name" class="mt-1 flex items-center gap-2">
                                                    <span
                                                        class="font-medium text-base-primary"
                                                        :class="{
                                                            'text-green-600 dark:text-green-400': match.result === 'player2_win',
                                                        }"
                                                    >
                                                        {{ match.player_2_name }}
                                                    </span>
                                                    <span
                                                        v-if="match.player_2_score !== null"
                                                        class="rounded bg-muted px-1.5 py-0.5 text-sm font-semibold text-base-secondary"
                                                    >
                                                        {{ match.player_2_score }}
                                                    </span>
                                                </div>

                                                <div v-else class="mt-1 text-sm italic text-base-muted">
                                                    {{ t('tournaments.public.bye') }}
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Result Badge -->
                                        <div class="ml-4 flex-shrink-0">
                                            <span
                                                class="rounded-full px-2.5 py-1 text-xs font-medium"
                                                :class="getResultBadgeClass(match)"
                                            >
                                                {{ match.result_label }}
                                            </span>

                                            <!-- Disputed indicator -->
                                            <span
                                                v-if="match.is_disputed"
                                                class="ml-2 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-400"
                                            >
                                                {{ t('tournaments.public.disputed') }}
                                            </span>

                                            <!-- Needs confirmation indicator -->
                                            <span
                                                v-else-if="match.needs_confirmation"
                                                class="ml-2 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400"
                                            >
                                                {{ t('tournaments.public.pending_confirmation') }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>
