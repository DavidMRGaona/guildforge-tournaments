<script setup lang="ts">
import { ChevronDown, Plus, Trophy } from '@lucide/vue';
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
    <div class="space-y-10">
        <!-- Header with View All button: the page has its own empty state, so it is always shown -->
        <div class="flex justify-end">
            <Link
                href="/torneos/mis-torneos"
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >
                <Trophy class="h-4 w-4" aria-hidden="true" />
                {{ t('tournaments.my_tournaments.view_all') }}
            </Link>
        </div>

        <template v-if="profileTournaments && hasAnyTournaments">
            <!-- In Progress section -->
            <section v-if="inProgressTournaments.length > 0">
                <h2 class="mb-6 flex items-center gap-2 text-lg font-semibold text-base-primary">
                    <span
                        class="flex h-6 w-6 items-center justify-center rounded-full bg-info-light text-xs font-bold text-base-primary"
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
                        class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-500/10 text-xs font-bold text-primary"
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
                    class="rounded-lg border border-dashed border-default bg-base-secondary/10 p-6 text-center text-sm text-base-muted"
                >
                    {{ t('tournaments.my_tournaments.no_upcoming') }}
                </p>
            </section>

            <!-- Past section (collapsible) -->
            <section v-if="pastTournaments.length > 0" class="border-t border-default pt-8">
                <button
                    type="button"
                    class="mb-4 flex w-full items-center justify-between rounded-lg bg-base-secondary/10 px-4 py-3 text-left transition-colors hover:bg-base-secondary/20"
                    :aria-expanded="showPastSection"
                    @click="togglePastSection"
                >
                    <span class="flex items-center gap-2 text-lg font-semibold text-base-primary">
                        <span
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-base-secondary/10 text-xs font-bold text-base-secondary"
                        >
                            {{ pastTournaments.length }}
                        </span>
                        {{ t('tournaments.my_tournaments.history') }}
                    </span>
                    <ChevronDown
                        :class="['h-5 w-5 text-base-muted transition-transform', showPastSection ? 'rotate-180' : '']"
                        aria-hidden="true"
                    />
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
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-default bg-transparent py-3 text-sm font-medium text-base-secondary transition-colors hover:bg-base-secondary/10"
                        @click="loadMore"
                    >
                        <Plus class="h-4 w-4" aria-hidden="true" />
                        {{ t('tournaments.my_tournaments.load_more', { count: remainingPastCount }) }}
                    </button>
                </div>
            </section>
        </template>

        <!-- Empty state when no tournaments at all -->
        <div
            v-else
            class="rounded-lg border border-dashed border-default bg-base-secondary/10 p-12 text-center"
        >
            <Trophy class="mx-auto h-12 w-12 text-base-muted" aria-hidden="true" />
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
    </div>
</template>

