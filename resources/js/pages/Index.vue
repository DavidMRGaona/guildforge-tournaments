<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { PaginatedResponse } from '@/types';
import type { TournamentListItem, TournamentStatusFilter as TournamentStatusFilterType } from '../types/tournaments';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import TournamentList from '../components/TournamentList.vue';
import TournamentStatusFilter from '../components/TournamentStatusFilter.vue';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useSeo } from '@/composables/useSeo';
import { usePagination } from '@/composables/usePagination';

interface Props {
    tournaments: PaginatedResponse<TournamentListItem>;
    currentFilter: TournamentStatusFilterType;
}

const props = withDefaults(defineProps<Props>(), {
    currentFilter: 'all',
});

const { t } = useI18n();

useSeo({
    title: t('tournaments.title'),
    description: t('tournaments.listing.subtitle'),
});

const isNavigating = ref(false);

const { firstItemNumber, lastItemNumber, hasPagination, goToPrev, goToNext, canGoPrev, canGoNext } =
    usePagination(() => props.tournaments);

function handlePrev(): void {
    isNavigating.value = true;
    goToPrev();
}

function handleNext(): void {
    isNavigating.value = true;
    goToNext();
}
</script>

<template>
    <DefaultLayout>
        <div class="bg-white shadow dark:bg-stone-800 dark:shadow-stone-900/50">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <h1 class="text-3xl font-bold tracking-tight text-stone-900 dark:text-stone-100">
                    {{ t('tournaments.title') }}
                </h1>
                <p class="mt-2 text-lg text-stone-600 dark:text-stone-400">
                    {{ t('tournaments.listing.subtitle') }}
                </p>
            </div>
        </div>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <TournamentStatusFilter :current-filter="props.currentFilter" class="mb-6" />

            <TournamentList :tournaments="props.tournaments.data" />

            <div
                v-if="hasPagination"
                class="mt-8 flex items-center justify-between border-t border-stone-200 pt-6 dark:border-stone-700"
            >
                <p class="text-sm text-stone-700 dark:text-stone-300">
                    {{ t('common.showing') }}
                    <span class="font-medium">
                        {{ firstItemNumber }}
                    </span>
                    -
                    <span class="font-medium">
                        {{ lastItemNumber }}
                    </span>
                    {{ t('common.of') }}
                    <span class="font-medium">{{ props.tournaments.meta.total }}</span>
                </p>

                <div class="flex gap-2">
                    <BaseButton
                        variant="secondary"
                        :disabled="!canGoPrev"
                        :loading="isNavigating"
                        @click="handlePrev"
                    >
                        {{ t('common.previous') }}
                    </BaseButton>
                    <BaseButton
                        variant="secondary"
                        :disabled="!canGoNext"
                        :loading="isNavigating"
                        @click="handleNext"
                    >
                        {{ t('common.next') }}
                    </BaseButton>
                </div>
            </div>
        </main>
    </DefaultLayout>
</template>
