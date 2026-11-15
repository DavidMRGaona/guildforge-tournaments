<script setup lang="ts">
import { Calendar, ChartColumn, Star } from '@lucide/vue';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type {
    PublicProfileTournament,
    PublicProfileTournamentsProps,
} from '../../types/tournaments';
import { useTournaments } from '../../composables/useTournaments';
import { VENUE_TIMEZONE } from '@/utils/datetime';

const props = defineProps<PublicProfileTournamentsProps>();
const { t, locale } = useI18n();
const { formatPoints } = useTournaments();

interface Group {
    key: 'ongoing' | 'upcoming' | 'recent';
    title: string;
    tournaments: PublicProfileTournament[];
}

// Only the groups with something in them get a heading
const groups = computed<Group[]>(() =>
    (
        [
            {
                key: 'ongoing',
                title: t('tournaments.public_profile.ongoing'),
                tournaments: props.ongoing,
            },
            {
                key: 'upcoming',
                title: t('tournaments.public_profile.upcoming'),
                tournaments: props.upcoming,
            },
            {
                key: 'recent',
                title: t('tournaments.public_profile.recent'),
                tournaments: props.recent,
            },
        ] satisfies Group[]
    ).filter((group) => group.tournaments.length > 0)
);

// One point is the only singular: 0,5 or 1,5 points are plural, whatever the decimals
function pluralCount(points: number): number {
    return points === 1 ? 1 : 2;
}

function formattedDate(startsAt: string | null): string | null {
    if (startsAt === null) return null;

    return new Date(startsAt).toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <div class="space-y-6">
        <section v-for="group in groups" :key="group.key" :data-group="group.key">
            <h3 class="mb-2 text-sm font-semibold text-base-muted">{{ group.title }}</h3>
            <ul class="divide-y divide-[color:var(--color-border)]">
                <li
                    v-for="tournament in group.tournaments"
                    :key="tournament.id"
                    class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0"
                >
                    <Link
                        :href="tournament.url"
                        class="font-medium text-[color:var(--color-text-primary)] hover:text-primary"
                    >
                        {{ tournament.name }}
                    </Link>
                    <p v-if="tournament.eventTitle" class="text-sm text-primary">
                        {{ tournament.eventTitle }}
                    </p>
                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-base-muted"
                    >
                        <span
                            v-if="formattedDate(tournament.startsAt)"
                            class="flex items-center gap-1.5"
                        >
                            <Calendar class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ formattedDate(tournament.startsAt) }}
                        </span>
                        <span v-if="tournament.round !== null" data-field="round">
                            {{ t('tournaments.public_profile.round', { round: tournament.round }) }}
                        </span>
                        <span
                            v-if="tournament.position !== null"
                            data-field="position"
                            class="flex items-center gap-1"
                        >
                            <ChartColumn class="h-4 w-4 text-primary" aria-hidden="true" />
                            {{
                                t('tournaments.public_profile.position', {
                                    position: tournament.position,
                                })
                            }}
                        </span>
                        <span
                            v-if="tournament.points !== null && tournament.position !== null"
                            data-field="points"
                            class="flex items-center gap-1"
                        >
                            <Star class="h-4 w-4 text-primary" aria-hidden="true" />
                            {{
                                t(
                                    'tournaments.public_profile.points',
                                    { points: formatPoints(tournament.points) },
                                    pluralCount(tournament.points)
                                )
                            }}
                        </span>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
