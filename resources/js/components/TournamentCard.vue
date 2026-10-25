<script setup lang="ts">
import { Calendar, CircleCheck, Trophy, Users } from '@lucide/vue';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentListItem } from '../types/tournaments';
import { statusBadgeClasses } from '../utils/statusColors';
import BaseCard from '@/components/ui/BaseCard.vue';
import { buildCardImageUrl } from '@/utils/cloudinary';
import { VENUE_TIMEZONE } from '@/utils/datetime';

interface Props {
    tournament: TournamentListItem;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const tournamentImageUrl = computed(() => buildCardImageUrl(props.tournament.imagePublicId));

const statusColorClasses = computed(() => statusBadgeClasses(props.tournament.statusColor));

const participantInfo = computed(() => {
    const current = props.tournament.participantCount;
    const max = props.tournament.maxParticipants;

    if (max) {
        return `${current}/${max}`;
    }
    return String(current);
});

function formatShortDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

const formattedDate = computed(() => {
    if (props.tournament.isInProgress && props.tournament.startedAt) {
        return formatShortDate(props.tournament.startedAt);
    }
    if (props.tournament.isFinished && props.tournament.completedAt) {
        return formatShortDate(props.tournament.completedAt);
    }
    if (props.tournament.registrationOpensAt) {
        return formatShortDate(props.tournament.registrationOpensAt);
    }
    return null;
});

const dateLabel = computed(() => {
    if (props.tournament.isInProgress) {
        return t('tournaments.listing.startedAt');
    }
    if (props.tournament.isFinished) {
        return t('tournaments.listing.completedAt');
    }
    return t('tournaments.listing.registrationOpensAt');
});

const roundInfo = computed(() => {
    if (props.tournament.isInProgress && props.tournament.currentRound && props.tournament.maxRounds) {
        return t('tournaments.listing.roundProgress', {
            current: props.tournament.currentRound,
            max: props.tournament.maxRounds,
        });
    }
    return null;
});

function getExcerpt(text: string | null, maxLength: number): string {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength).trim() + '...';
}
</script>

<template>
    <Link
        :href="`/torneos/${props.tournament.slug}`"
        :aria-label="t('tournaments.listing.viewTournament', { name: props.tournament.name })"
        class="block transition-transform duration-200 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface"
    >
        <BaseCard :padding="false">
            <!-- Image section -->
            <template v-if="tournamentImageUrl" #header>
                <img
                    :src="tournamentImageUrl"
                    :alt="props.tournament.name"
                    loading="lazy"
                    class="aspect-video h-40 w-full object-cover"
                />
            </template>
            <template v-else #header>
                <div
                    class="flex aspect-video h-40 w-full items-center justify-center bg-gradient-to-br from-primary-500 to-primary-700"
                >
                    <Trophy class="h-12 w-12 text-white/50" aria-hidden="true" />
                </div>
            </template>

            <div class="p-4">
                <!-- Status badge and participant count -->
                <div class="mb-3 flex items-center justify-between">
                    <span
                        :class="[
                            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                            statusColorClasses,
                        ]"
                    >
                        {{ props.tournament.statusLabel }}
                    </span>

                    <span
                        v-if="props.tournament.maxParticipants || props.tournament.participantCount > 0"
                        class="flex items-center text-sm text-base-muted"
                    >
                        <Users class="mr-1 h-4 w-4" aria-hidden="true" />
                        {{ participantInfo }}
                    </span>
                </div>

                <!-- Tournament name -->
                <h3
                    class="mb-2 line-clamp-2 text-lg font-semibold text-base-primary"
                >
                    {{ props.tournament.name }}
                </h3>

                <!-- Round progress (if in progress) -->
                <p
                    v-if="roundInfo"
                    class="mb-2 text-sm font-medium text-primary"
                >
                    {{ roundInfo }}
                </p>

                <!-- Date info -->
                <p
                    v-if="formattedDate"
                    class="mb-2 flex items-center text-sm text-base-muted"
                >
                    <Calendar class="mr-1.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
                    <span class="sr-only">{{ dateLabel }}:</span>
                    {{ formattedDate }}
                </p>

                <!-- Registration badge -->
                <div
                    v-if="props.tournament.isRegistrationOpen && props.tournament.hasCapacity"
                    class="mb-3"
                >
                    <span
                        class="inline-flex items-center rounded-full bg-success-light px-2 py-0.5 text-xs font-medium text-success"
                    >
                        <CircleCheck class="mr-1 h-3 w-3" aria-hidden="true" />
                        {{ t('tournaments.listing.registrationOpen') }}
                    </span>
                </div>

                <!-- Description excerpt -->
                <p
                    v-if="props.tournament.description"
                    class="line-clamp-3 text-sm text-base-secondary"
                >
                    {{ getExcerpt(props.tournament.description, 150) }}
                </p>
            </div>
        </BaseCard>
    </Link>
</template>
