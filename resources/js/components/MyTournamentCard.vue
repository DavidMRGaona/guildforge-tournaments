<script setup lang="ts">
import {
    Calendar,
    ChartColumn,
    ChevronRight,
    CircleCheck,
    Lightbulb,
    Star,
    Trophy,
} from '@lucide/vue';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { UserTournament } from '../types/tournaments';
import { statusBadgeClasses } from '../utils/statusColors';
import { buildCardImageUrl } from '@/utils/cloudinary';
import { VENUE_TIMEZONE } from '@/utils/datetime';

interface Props {
    tournament: UserTournament;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const tournamentImageUrl = computed(() => buildCardImageUrl(props.tournament.imagePublicId));

const statusColorClasses = computed(() => statusBadgeClasses(props.tournament.statusColor));

const participantStatusColorClasses = computed(() =>
    statusBadgeClasses(props.tournament.participantStatusColor)
);

const formattedDate = computed(() => {
    if (!props.tournament.startsAt) return null;
    return new Date(props.tournament.startsAt).toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
});

const formattedTime = computed(() => {
    if (!props.tournament.startsAt) return null;
    return new Date(props.tournament.startsAt).toLocaleTimeString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        hour: '2-digit',
        minute: '2-digit',
    });
});

const positionText = computed(() => {
    if (props.tournament.position === null) return null;
    return t('tournaments.my_tournaments.position_of', {
        position: props.tournament.position,
        total: props.tournament.totalParticipants,
    });
});

const checkInDeadlineFormatted = computed(() => {
    if (!props.tournament.checkInDeadline) return null;
    return new Date(props.tournament.checkInDeadline).toLocaleTimeString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        hour: '2-digit',
        minute: '2-digit',
    });
});

const tournamentUrl = computed(() => `/torneos/${props.tournament.slug}`);
const checkInUrl = computed(() => `/torneos/${props.tournament.slug}/check-in`);
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-lg border border-[color:var(--color-border)] bg-surface transition-all hover:border-primary hover:shadow-md"
    >
        <div class="flex flex-col sm:flex-row">
            <!-- Image section -->
            <div class="relative h-32 w-full shrink-0 sm:h-auto sm:w-40">
                <img
                    v-if="tournamentImageUrl"
                    :src="tournamentImageUrl"
                    :alt="tournament.name"
                    loading="lazy"
                    class="h-full w-full object-cover"
                />
                <div
                    v-else
                    class="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary-500 to-primary-700"
                >
                    <Trophy class="h-10 w-10 text-white/50" aria-hidden="true" />
                </div>
            </div>

            <!-- Content section -->
            <div class="flex flex-1 flex-col p-4">
                <!-- Header row: Title + badges -->
                <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <Link
                            :href="tournamentUrl"
                            class="block text-lg font-semibold text-[color:var(--color-text-primary)] hover:text-primary"
                        >
                            {{ tournament.name }}
                        </Link>
                        <p v-if="tournament.eventName" class="text-sm text-base-muted">
                            {{ tournament.eventName }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <span
                            :class="[
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                statusColorClasses,
                            ]"
                        >
                            {{ tournament.statusLabel }}
                        </span>
                        <span
                            :class="[
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                participantStatusColorClasses,
                            ]"
                        >
                            {{ tournament.participantStatusLabel }}
                        </span>
                    </div>
                </div>

                <!-- Date and time -->
                <div
                    v-if="formattedDate"
                    class="mb-3 flex items-center gap-1.5 text-sm text-base-muted"
                >
                    <Calendar class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{{ formattedDate }}</span>
                    <span v-if="formattedTime" class="text-base-muted">&middot;</span>
                    <span v-if="formattedTime">{{ formattedTime }}</span>
                </div>

                <!-- Stats row (position + points) - only for in progress or finished -->
                <div
                    v-if="tournament.isInProgress || tournament.isPast"
                    class="mb-3 flex flex-wrap items-center gap-4 text-sm"
                >
                    <!-- Position -->
                    <div v-if="positionText" class="flex items-center gap-1.5 text-base-secondary">
                        <ChartColumn class="h-4 w-4 text-primary" aria-hidden="true" />
                        <span>{{ positionText }}</span>
                    </div>

                    <!-- Points -->
                    <div class="flex items-center gap-1.5 text-base-secondary">
                        <Star class="h-4 w-4 text-primary" aria-hidden="true" />
                        <span
                            >{{ tournament.points }}
                            {{ t('tournaments.my_tournaments.points') }}</span
                        >
                    </div>
                </div>

                <!-- Next match info - only for in progress tournaments -->
                <div
                    v-if="tournament.isInProgress && tournament.nextMatch"
                    class="mb-3 rounded-md bg-page p-2.5"
                >
                    <div class="flex items-center gap-2 text-sm">
                        <Lightbulb class="h-4 w-4 shrink-0 text-primary" aria-hidden="true" />
                        <div class="flex flex-wrap items-center gap-x-2 text-base-secondary">
                            <span class="font-medium"
                                >{{ t('tournaments.my_tournaments.next_match') }}:</span
                            >
                            <span
                                >{{ t('tournaments.my_tournaments.round') }}
                                {{ tournament.nextMatch.roundNumber }}</span
                            >
                            <span v-if="tournament.nextMatch.isBye" class="text-primary">
                                ({{ t('tournaments.my_tournaments.bye') }})
                            </span>
                            <template v-else-if="tournament.nextMatch.opponentName">
                                <span
                                    >{{ t('tournaments.my_tournaments.vs') }}
                                    {{ tournament.nextMatch.opponentName }}</span
                                >
                                <span
                                    v-if="tournament.nextMatch.tableNumber"
                                    class="text-base-muted"
                                >
                                    &middot; {{ t('tournaments.my_tournaments.table') }}
                                    {{ tournament.nextMatch.tableNumber }}
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Actions row -->
                <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
                    <!-- Check-in button -->
                    <Link
                        v-if="tournament.canCheckIn"
                        :href="checkInUrl"
                        class="inline-flex items-center gap-1.5 rounded-md bg-green-700 px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-green-800 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 focus:ring-offset-surface"
                    >
                        <CircleCheck class="h-4 w-4" aria-hidden="true" />
                        {{ t('tournaments.my_tournaments.check_in_now') }}
                        <span v-if="checkInDeadlineFormatted" class="text-white/90">
                            ({{ t('tournaments.my_tournaments.until') }}
                            {{ checkInDeadlineFormatted }})
                        </span>
                    </Link>

                    <!-- View tournament link -->
                    <Link
                        :href="tournamentUrl"
                        class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                    >
                        {{ t('tournaments.my_tournaments.view_tournament') }}
                        <ChevronRight class="h-4 w-4" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
