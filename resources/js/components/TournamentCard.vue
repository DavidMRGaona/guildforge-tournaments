<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentListItem } from '../types/tournaments';
import BaseCard from '@/components/ui/BaseCard.vue';
import { buildCardImageUrl } from '@/utils/cloudinary';

interface Props {
    tournament: TournamentListItem;
}

const props = defineProps<Props>();

const { t, locale } = useI18n();

const tournamentImageUrl = computed(() => buildCardImageUrl(props.tournament.imagePublicId));

const statusColorClasses = computed(() => {
    const colorMap: Record<string, string> = {
        green: 'bg-success-light text-success',
        blue: 'bg-info-light text-info',
        amber: 'bg-warning-light text-warning',
        gray: 'bg-muted text-base-secondary',
        red: 'bg-error-light text-error',
    };
    return colorMap[props.tournament.statusColor] || colorMap.gray;
});

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
        class="block transition-transform duration-200 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-page"
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
                    class="flex aspect-video h-40 w-full items-center justify-center bg-gradient-to-br from-primary-500 to-stone-600 dark:from-primary-600 dark:to-stone-700"
                >
                    <svg
                        class="h-12 w-12 text-white/50"
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
                        <svg
                            class="mr-1 h-4 w-4"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
                            />
                        </svg>
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
                    <svg
                        class="mr-1.5 h-4 w-4 flex-shrink-0"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                        />
                    </svg>
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
                        <svg
                            class="mr-1 h-3 w-3"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                            aria-hidden="true"
                        >
                            <path
                                fill-rule="evenodd"
                                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                clip-rule="evenodd"
                            />
                        </svg>
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
