<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentStandingsProps } from '../../types/tournaments';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import { useTournaments } from '../../composables/useTournaments';

const props = defineProps<TournamentStandingsProps>();

const { t } = useI18n();
const { formatPoints, formatPercentage } = useTournaments();
</script>

<template>
    <DefaultLayout>
        <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
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
                        {{ t('tournaments.public.standings') }}
                    </h1>
                    <p class="mt-1 text-base-muted">
                        {{ tournament.name }}
                    </p>
                </div>

                <div class="p-6">
                    <div v-if="standings.length === 0" class="py-8 text-center text-base-muted">
                        {{ t('tournaments.public.no_standings') }}
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-default">
                            <thead>
                                <tr class="text-left text-xs font-medium uppercase tracking-wider text-base-muted">
                                    <th class="px-3 py-3">#</th>
                                    <th class="px-3 py-3">{{ t('tournaments.public.player') }}</th>
                                    <th class="px-3 py-3 text-right">{{ t('tournaments.public.points') }}</th>
                                    <th class="px-3 py-3 text-center">{{ t('tournaments.public.matches') }}</th>
                                    <th class="hidden px-3 py-3 text-center md:table-cell">{{ t('tournaments.public.wins') }}</th>
                                    <th class="hidden px-3 py-3 text-center md:table-cell">{{ t('tournaments.public.draws') }}</th>
                                    <th class="hidden px-3 py-3 text-center md:table-cell">{{ t('tournaments.public.losses') }}</th>
                                    <th class="hidden px-3 py-3 text-right lg:table-cell">{{ t('tournaments.public.buchholz') }}</th>
                                    <th class="hidden px-3 py-3 text-right lg:table-cell">{{ t('tournaments.public.owp') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-default">
                                <tr
                                    v-for="standing in standings"
                                    :key="standing.participant_id"
                                    class="text-base-primary hover:bg-base-secondary/10"
                                    :class="{
                                        'bg-primary-500/10': standing.rank === 1,
                                        'bg-base-secondary/10': standing.rank === 2,
                                        'bg-warning-light': standing.rank === 3,
                                    }"
                                >
                                    <td class="px-3 py-3">
                                        <span
                                            class="font-bold"
                                            :class="{
                                                'text-primary': standing.rank === 1,
                                                'text-base-muted': standing.rank === 2,
                                                'text-warning': standing.rank === 3,
                                            }"
                                        >
                                            {{ standing.rank }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 font-medium">
                                        {{ standing.participant_name }}
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <span class="font-bold text-primary">
                                            {{ formatPoints(standing.points) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-center text-base-secondary">
                                        {{ standing.matches_played }}
                                    </td>
                                    <td class="hidden px-3 py-3 text-center text-success md:table-cell">
                                        {{ standing.wins }}
                                    </td>
                                    <td class="hidden px-3 py-3 text-center text-warning md:table-cell">
                                        {{ standing.draws }}
                                    </td>
                                    <td class="hidden px-3 py-3 text-center text-error md:table-cell">
                                        {{ standing.losses }}
                                    </td>
                                    <td class="hidden px-3 py-3 text-right text-sm text-base-muted lg:table-cell">
                                        {{ formatPoints(standing.buchholz) }}
                                    </td>
                                    <td class="hidden px-3 py-3 text-right text-sm text-base-muted lg:table-cell">
                                        {{ formatPercentage(standing.opponent_win_percentage) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Legend -->
                    <div class="mt-6 border-t border-default pt-4">
                        <h3 class="mb-2 text-sm font-semibold text-base-secondary">
                            {{ t('tournaments.public.tiebreakers_legend') }}
                        </h3>
                        <dl class="grid grid-cols-1 gap-2 text-sm text-base-secondary sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <dt class="font-medium">{{ t('tournaments.public.buchholz') }}</dt>
                                <dd>{{ t('tournaments.public.buchholz_description') }}</dd>
                            </div>
                            <div>
                                <dt class="font-medium">{{ t('tournaments.public.owp') }}</dt>
                                <dd>{{ t('tournaments.public.owp_description') }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>
