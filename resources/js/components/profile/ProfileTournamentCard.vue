<script setup lang="ts">
import { Calendar, ChartColumn, Star } from '@lucide/vue';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { UserTournament } from '../../types/tournaments';
import { statusBadgeClasses } from '../../utils/statusColors';
import { VENUE_TIMEZONE } from '@/utils/datetime';

interface Props {
    tournament: UserTournament;
}

const props = defineProps<Props>();
const { t, locale } = useI18n();

const tournamentUrl = computed(() => `/torneos/${props.tournament.slug}`);

const statusColorClasses = computed(() => statusBadgeClasses(props.tournament.statusColor));

const formattedDate = computed(() => {
    if (!props.tournament.startsAt) return null;
    return new Date(props.tournament.startsAt).toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
});

const positionText = computed(() => {
    if (props.tournament.position === null) return null;
    return t('tournaments.my_tournaments.position_of', {
        position: props.tournament.position,
        total: props.tournament.totalParticipants,
    });
});
</script>

<template>
    <Link
        :href="tournamentUrl"
        class="group block rounded-lg border border-[color:var(--color-border)] bg-surface p-4 transition-all hover:border-primary hover:shadow-sm"
    >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <!-- Left: Title and details -->
            <div class="min-w-0 flex-1">
                <h4
                    class="truncate text-base font-medium text-[color:var(--color-text-primary)] group-hover:text-primary"
                >
                    {{ tournament.name }}
                </h4>
                <p v-if="tournament.eventName" class="mt-0.5 text-sm text-primary">
                    {{ tournament.eventName }}
                </p>

                <!-- Date -->
                <div
                    v-if="formattedDate"
                    class="mt-2 flex items-center gap-1.5 text-sm text-base-muted"
                >
                    <Calendar class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{{ formattedDate }}</span>
                </div>

                <!-- Position and points for in progress/past tournaments -->
                <div
                    v-if="tournament.isInProgress || tournament.isPast"
                    class="mt-2 flex flex-wrap items-center gap-3 text-sm text-base-muted"
                >
                    <span v-if="positionText" class="flex items-center gap-1">
                        <ChartColumn class="h-4 w-4 text-primary" aria-hidden="true" />
                        {{ positionText }}
                    </span>
                    <span class="flex items-center gap-1">
                        <Star class="h-4 w-4 text-primary" aria-hidden="true" />
                        {{ tournament.points }} {{ t('tournaments.my_tournaments.points') }}
                    </span>
                </div>
            </div>

            <!-- Right: Status badge -->
            <div class="flex items-center">
                <span
                    :class="[
                        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                        statusColorClasses,
                    ]"
                >
                    {{ tournament.statusLabel }}
                </span>
            </div>
        </div>
    </Link>
</template>
