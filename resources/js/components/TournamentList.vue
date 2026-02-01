<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { TournamentListItem } from '../types/tournaments';
import TournamentCard from './TournamentCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import { useGridLayout, type GridColumns } from '@/composables/useGridLayout';

interface Props {
    tournaments: TournamentListItem[];
    columns?: GridColumns;
}

const props = withDefaults(defineProps<Props>(), {
    columns: 3,
});

const { t } = useI18n();

const { gridClasses } = useGridLayout(() => props.columns);
</script>

<template>
    <div v-if="props.tournaments.length > 0" class="grid gap-6" :class="gridClasses">
        <TournamentCard
            v-for="tournament in props.tournaments"
            :key="tournament.id"
            :tournament="tournament"
        />
    </div>

    <EmptyState
        v-else
        icon="calendar"
        :title="t('common.noResults')"
        :description="t('tournaments.listing.noTournaments')"
    />
</template>
