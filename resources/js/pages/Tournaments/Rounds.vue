<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentRoundsProps } from '../../types/tournaments';
import { useTournaments } from '../../composables/useTournaments';
import { statusBadgeClasses } from '../../utils/statusColors';

const props = defineProps<TournamentRoundsProps>();

const { t } = useI18n();
const { getMatchResultColor, getRoundStatusColor } = useTournaments();

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

const sortedRounds = computed(() => {
    return [...props.rounds].sort((a, b) => b.round.round_number - a.round.round_number);
});
</script>

<template>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <!-- Back link -->
        <div class="mb-6">
            <Link
                :href="`/torneos/${tournament.slug}`"
                class="inline-flex items-center text-sm text-[color:var(--color-text-secondary)] hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface"
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

        <div class="overflow-hidden rounded-lg bg-surface shadow">
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
                            class="flex w-full items-center justify-between p-4 text-left hover:bg-base-secondary/10"
                            :class="{
                                'bg-info-light': roundData.round.status === 'in_progress',
                            }"
                            @click="toggleRound(roundData.round.id)"
                        >
                            <div class="flex items-center gap-3">
                                <span class="text-lg font-semibold text-base-primary">
                                    {{ t('tournaments.public.round_number', { number: roundData.round.round_number }) }}
                                </span>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="statusBadgeClasses(getRoundStatusColor(roundData.round.status))"
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
                                    class="h-5 w-5 text-base-muted transition-transform"
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

                            <div v-else class="divide-y divide-default">
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
                                            class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded bg-base-secondary/10 text-sm font-medium text-base-secondary"
                                        >
                                            {{ match.table_number }}
                                        </div>

                                        <!-- Players -->
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="font-medium text-base-primary"
                                                    :class="{
                                                        'text-success': match.result === 'player_one_win',
                                                    }"
                                                >
                                                    {{ match.player_1_name }}
                                                </span>
                                                <span
                                                    v-if="match.player_1_score !== null"
                                                    class="rounded bg-base-secondary/10 px-1.5 py-0.5 text-sm font-semibold text-base-secondary"
                                                >
                                                    {{ match.player_1_score }}
                                                </span>
                                            </div>

                                            <div v-if="match.player_2_name" class="mt-1 flex items-center gap-2">
                                                <span
                                                    class="font-medium text-base-primary"
                                                    :class="{
                                                        'text-success': match.result === 'player_two_win',
                                                    }"
                                                >
                                                    {{ match.player_2_name }}
                                                </span>
                                                <span
                                                    v-if="match.player_2_score !== null"
                                                    class="rounded bg-base-secondary/10 px-1.5 py-0.5 text-sm font-semibold text-base-secondary"
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
                                            :class="statusBadgeClasses(getMatchResultColor(match.result))"
                                        >
                                            {{ match.result_label }}
                                        </span>

                                        <!-- Disputed indicator -->
                                        <span
                                            v-if="match.is_disputed"
                                            class="ml-2 rounded-full bg-error-light px-2 py-0.5 text-xs font-medium text-base-primary"
                                        >
                                            {{ t('tournaments.public.disputed') }}
                                        </span>

                                        <!-- Needs confirmation indicator -->
                                        <span
                                            v-else-if="match.needs_confirmation"
                                            class="ml-2 rounded-full bg-warning-light px-2 py-0.5 text-xs font-medium text-base-primary"
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
</template>
