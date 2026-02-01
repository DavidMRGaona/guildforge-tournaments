<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ProfileTournamentCard from './ProfileTournamentCard.vue';
import type { ProfileTournamentsData, UserTournament } from '../../types/tournaments';

interface Props {
    profileTournaments: ProfileTournamentsData | null;
}

const props = defineProps<Props>();
const { t } = useI18n();

const INITIAL_PAST_COUNT = 5;
const LOAD_MORE_COUNT = 5;

const showPastSection = ref(false);
const visiblePastCount = ref(INITIAL_PAST_COUNT);

const upcomingTournaments = computed<UserTournament[]>(() => props.profileTournaments?.upcoming ?? []);
const inProgressTournaments = computed<UserTournament[]>(() => props.profileTournaments?.inProgress ?? []);
const pastTournaments = computed<UserTournament[]>(() => props.profileTournaments?.past ?? []);

const visiblePastTournaments = computed(() => pastTournaments.value.slice(0, visiblePastCount.value));
const hasMorePast = computed(() => visiblePastCount.value < pastTournaments.value.length);
const remainingPastCount = computed(() => pastTournaments.value.length - visiblePastCount.value);

const hasAnyTournaments = computed(() => {
    return (
        upcomingTournaments.value.length > 0 ||
        inProgressTournaments.value.length > 0 ||
        pastTournaments.value.length > 0
    );
});

function togglePastSection(): void {
    showPastSection.value = !showPastSection.value;
}

function loadMore(): void {
    visiblePastCount.value = Math.min(visiblePastCount.value + LOAD_MORE_COUNT, pastTournaments.value.length);
}
</script>

<template>
    <div v-if="profileTournaments && hasAnyTournaments" class="space-y-10">
        <!-- Header with View All button -->
        <div class="flex justify-end">
            <Link
                href="/torneos/mis-torneos"
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m3.044-.001a6.726 6.726 0 0 0 2.749-1.35m0 0a6.772 6.772 0 0 1-2.697 3.423m0 0v.25M14.521 13.401a6.913 6.913 0 0 1-5.043.001M18.75 4.236c.982.143 1.954.317 2.916.52A6.003 6.003 0 0 1 16.27 9.728m2.48-5.492V2.721"
                    />
                </svg>
                {{ t('tournaments.my_tournaments.view_all') }}
            </Link>
        </div>

        <!-- In Progress section -->
        <section v-if="inProgressTournaments.length > 0">
            <h2 class="mb-6 flex items-center gap-2 text-lg font-semibold text-base-primary">
                <span
                    class="flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                >
                    {{ inProgressTournaments.length }}
                </span>
                {{ t('tournaments.my_tournaments.in_progress') }}
            </h2>

            <div class="space-y-3">
                <ProfileTournamentCard
                    v-for="tournament in inProgressTournaments"
                    :key="tournament.id"
                    :tournament="tournament"
                />
            </div>
        </section>

        <!-- Upcoming section -->
        <section>
            <h2 class="mb-6 flex items-center gap-2 text-lg font-semibold text-base-primary">
                <span
                    class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700 dark:bg-primary-900/30 dark:text-primary-400"
                >
                    {{ upcomingTournaments.length }}
                </span>
                {{ t('tournaments.my_tournaments.upcoming') }}
            </h2>

            <div v-if="upcomingTournaments.length > 0" class="space-y-3">
                <ProfileTournamentCard
                    v-for="tournament in upcomingTournaments"
                    :key="tournament.id"
                    :tournament="tournament"
                />
            </div>

            <p
                v-else
                class="rounded-lg border border-dashed border-default bg-muted p-6 text-center text-sm text-base-muted"
            >
                {{ t('tournaments.my_tournaments.no_upcoming') }}
            </p>
        </section>

        <!-- Past section (collapsible) -->
        <section v-if="pastTournaments.length > 0" class="border-t border-default pt-8">
            <button
                type="button"
                class="mb-4 flex w-full items-center justify-between rounded-lg bg-muted px-4 py-3 text-left transition-colors hover:bg-stone-200 dark:hover:bg-stone-700"
                :aria-expanded="showPastSection"
                @click="togglePastSection"
            >
                <span class="flex items-center gap-2 text-lg font-semibold text-base-primary">
                    <span
                        class="flex h-6 w-6 items-center justify-center rounded-full bg-stone-200 text-xs font-bold text-stone-600 dark:bg-stone-700 dark:text-stone-400"
                    >
                        {{ pastTournaments.length }}
                    </span>
                    {{ t('tournaments.my_tournaments.history') }}
                </span>
                <svg
                    :class="['h-5 w-5 text-stone-500 transition-transform', showPastSection ? 'rotate-180' : '']"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            <div v-if="showPastSection" class="space-y-3">
                <ProfileTournamentCard
                    v-for="tournament in visiblePastTournaments"
                    :key="tournament.id"
                    :tournament="tournament"
                />

                <!-- Load more button -->
                <button
                    v-if="hasMorePast"
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-lg border border-default bg-surface py-3 text-sm font-medium text-base-secondary transition-colors hover:bg-stone-50 dark:hover:bg-stone-700"
                    @click="loadMore"
                >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                        />
                    </svg>
                    {{ t('tournaments.my_tournaments.load_more', { count: remainingPastCount }) }}
                </button>
            </div>
        </section>
    </div>

    <!-- Empty state when no tournaments at all -->
    <div
        v-else
        class="rounded-lg border border-dashed border-default bg-muted p-12 text-center"
    >
        <svg
            class="mx-auto h-12 w-12 text-stone-400"
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
        <h3 class="mt-4 text-lg font-medium text-base-primary">
            {{ t('tournaments.my_tournaments.empty_title') }}
        </h3>
        <p class="mt-1 text-sm text-base-muted">
            {{ t('tournaments.my_tournaments.empty_description') }}
        </p>
        <Link
            href="/torneos"
            class="mt-4 inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
            {{ t('tournaments.my_tournaments.browse_tournaments') }}
        </Link>
    </div>
</template>
