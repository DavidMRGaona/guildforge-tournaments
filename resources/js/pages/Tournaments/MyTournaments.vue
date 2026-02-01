<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { MyTournamentsPageProps } from '../../types/tournaments';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import MyTournamentCard from '../../components/MyTournamentCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useSeo } from '@/composables/useSeo';

const props = defineProps<MyTournamentsPageProps>();

const { t } = useI18n();

useSeo({
    title: t('tournaments.my_tournaments.title'),
});

const INITIAL_PAST_COUNT = 5;
const LOAD_MORE_COUNT = 5;

const showPastSection = ref(false);
const visiblePastCount = ref(INITIAL_PAST_COUNT);

const visiblePast = computed(() => props.past.slice(0, visiblePastCount.value));
const hasMorePast = computed(() => visiblePastCount.value < props.past.length);
const remainingPastCount = computed(() => props.past.length - visiblePastCount.value);

const isEmpty = computed(
    () => props.upcoming.length === 0 && props.inProgress.length === 0 && props.past.length === 0
);

function togglePastSection(): void {
    showPastSection.value = !showPastSection.value;
}

function loadMore(): void {
    visiblePastCount.value = Math.min(visiblePastCount.value + LOAD_MORE_COUNT, props.past.length);
}
</script>

<template>
    <DefaultLayout>
        <!-- Header -->
        <div class="bg-surface shadow dark:shadow-stone-900/50">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 class="text-3xl font-bold tracking-tight text-base-primary">
                            {{ t('tournaments.my_tournaments.title') }}
                        </h1>
                        <p class="mt-1 text-base-muted">
                            {{ t('tournaments.my_tournaments.subtitle') }}
                        </p>
                    </div>
                    <Link
                        href="/torneos"
                        class="inline-flex items-center gap-1.5 text-sm font-medium text-accent hover:text-accent-hover"
                    >
                        {{ t('tournaments.my_tournaments.browse_tournaments') }}
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

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Empty state -->
            <EmptyState
                v-if="isEmpty"
                :title="t('tournaments.my_tournaments.empty_title')"
                :description="t('tournaments.my_tournaments.empty_description')"
                icon="trophy"
            >
                <Link
                    href="/torneos"
                    class="inline-flex items-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-accent-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    {{ t('tournaments.my_tournaments.browse_tournaments') }}
                </Link>
            </EmptyState>

            <template v-else>
                <!-- In Progress section -->
                <section v-if="inProgress.length > 0" class="mb-10">
                    <h2 class="mb-4 flex items-center gap-2 text-xl font-semibold text-base-primary">
                        <span
                            class="flex h-7 w-7 items-center justify-center rounded-full bg-info-light text-sm font-bold text-info"
                        >
                            {{ inProgress.length }}
                        </span>
                        {{ t('tournaments.my_tournaments.in_progress') }}
                    </h2>
                    <div class="space-y-4">
                        <MyTournamentCard
                            v-for="tournament in inProgress"
                            :key="tournament.id"
                            :tournament="tournament"
                        />
                    </div>
                </section>

                <!-- Upcoming section -->
                <section v-if="upcoming.length > 0" class="mb-10">
                    <h2 class="mb-4 flex items-center gap-2 text-xl font-semibold text-base-primary">
                        <span
                            class="flex h-7 w-7 items-center justify-center rounded-full bg-warning-light text-sm font-bold text-warning"
                        >
                            {{ upcoming.length }}
                        </span>
                        {{ t('tournaments.my_tournaments.upcoming') }}
                    </h2>
                    <div class="space-y-4">
                        <MyTournamentCard
                            v-for="tournament in upcoming"
                            :key="tournament.id"
                            :tournament="tournament"
                        />
                    </div>
                </section>

                <!-- Past section (collapsible) -->
                <section v-if="past.length > 0" class="border-t border-default pt-8">
                    <button
                        type="button"
                        class="mb-4 flex w-full items-center justify-between rounded-lg bg-muted px-4 py-3 text-left transition-colors hover:bg-muted-hover"
                        :aria-expanded="showPastSection"
                        @click="togglePastSection"
                    >
                        <span class="flex items-center gap-2 text-xl font-semibold text-base-primary">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full bg-base-secondary/20 text-sm font-bold text-base-secondary dark:bg-base-secondary/10"
                            >
                                {{ past.length }}
                            </span>
                            {{ t('tournaments.my_tournaments.history') }}
                        </span>
                        <svg
                            :class="['h-5 w-5 text-base-muted transition-transform', showPastSection ? 'rotate-180' : '']"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div v-if="showPastSection" class="space-y-4">
                        <MyTournamentCard
                            v-for="tournament in visiblePast"
                            :key="tournament.id"
                            :tournament="tournament"
                        />

                        <!-- Load more button -->
                        <button
                            v-if="hasMorePast"
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-lg border border-default bg-surface py-3 text-sm font-medium text-base-secondary transition-colors hover:bg-muted"
                            @click="loadMore"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            {{ t('tournaments.my_tournaments.load_more', { count: remainingPastCount }) }}
                        </button>
                    </div>
                </section>
            </template>
        </main>
    </DefaultLayout>
</template>
