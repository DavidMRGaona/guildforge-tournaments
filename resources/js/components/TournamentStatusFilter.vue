<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentStatusFilter } from '../types/tournaments';

interface Props {
    currentFilter: TournamentStatusFilter;
}

const props = withDefaults(defineProps<Props>(), {
    currentFilter: 'all',
});

const { t } = useI18n();

interface FilterOption {
    value: TournamentStatusFilter;
    label: string;
}

const filterOptions = computed<FilterOption[]>(() => [
    { value: 'all', label: t('tournaments.listing.filters.all') },
    { value: 'active', label: t('tournaments.listing.filters.active') },
    { value: 'upcoming', label: t('tournaments.listing.filters.upcoming') },
    { value: 'past', label: t('tournaments.listing.filters.past') },
]);

function selectFilter(filter: TournamentStatusFilter): void {
    const url = new globalThis.URL('/torneos', window.location.origin);

    if (filter !== 'all') {
        url.searchParams.set('status', filter);
    }

    router.visit(url.pathname + (url.search ? url.search : ''), {
        preserveState: true,
        preserveScroll: true,
    });
}

function isActive(filter: TournamentStatusFilter): boolean {
    return props.currentFilter === filter;
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-stone-700 dark:text-stone-300">
            {{ t('tournaments.listing.filterBy') }}:
        </span>

        <button
            v-for="option in filterOptions"
            :key="option.value"
            type="button"
            :class="[
                'rounded-full px-3 py-1 text-sm font-medium transition-colors',
                isActive(option.value)
                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'
                    : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-stone-700 dark:text-stone-300 dark:hover:bg-stone-600',
            ]"
            @click="selectFilter(option.value)"
        >
            {{ option.label }}
        </button>
    </div>
</template>
