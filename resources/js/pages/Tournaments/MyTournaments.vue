<script setup lang="ts">
import { ChevronDown, ChevronRight, Plus } from '@lucide/vue';
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { MyTournamentsPageProps } from '../../types/tournaments';
import MyTournamentCard from '../../components/MyTournamentCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';

const props = defineProps<MyTournamentsPageProps>();

const { t } = useI18n();

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
    <!-- Header -->
    <div class="bg-surface shadow">
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
                    <ChevronRight class="h-4 w-4" aria-hidden="true" />
                </Link>
            </div>
        </div>
    </div>

    <!-- A plain container: the site layout already renders the page's <main> -->
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
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
                    class="mb-4 flex w-full items-center justify-between rounded-lg bg-base-secondary/10 px-4 py-3 text-left transition-colors hover:bg-base-secondary/20"
                    :aria-expanded="showPastSection"
                    @click="togglePastSection"
                >
                    <span class="flex items-center gap-2 text-xl font-semibold text-base-primary">
                        <span
                            class="flex h-7 w-7 items-center justify-center rounded-full bg-base-secondary/20 text-sm font-bold text-base-secondary"
                        >
                            {{ past.length }}
                        </span>
                        {{ t('tournaments.my_tournaments.history') }}
                    </span>
                    <ChevronDown
                        :class="[
                            'h-5 w-5 text-base-muted transition-transform',
                            showPastSection ? 'rotate-180' : '',
                        ]"
                        aria-hidden="true"
                    />
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
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-default bg-transparent py-3 text-sm font-medium text-base-secondary transition-colors hover:bg-base-secondary/10"
                        @click="loadMore"
                    >
                        <Plus class="h-4 w-4" aria-hidden="true" />
                        {{
                            t('tournaments.my_tournaments.load_more', { count: remainingPastCount })
                        }}
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>
