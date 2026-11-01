<script setup lang="ts">
import { ChevronLeft, TriangleAlert, X } from '@lucide/vue';
import { computed, ref, reactive, watch, nextTick, useId } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useGuestRegistrationMemory } from '../../composables/useGuestRegistrationMemory';
import type {
    TournamentShowProps,
    Standing,
    GuestRegistrationField,
    RegistrationJsonResponse,
} from '../../types/tournaments';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useTournaments } from '../../composables/useTournaments';
import { statusBadgeClasses } from '../../utils/statusColors';
import { useAuth } from '@/composables/useAuth';
import { useVerificationGate } from '@/composables/useVerificationGate';
import { useNotifications } from '@/composables/useNotifications';
import { useRoutes } from '@/composables/useRoutes';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import { csrfHeaders, isCsrfFailure } from '@/utils/csrf';

type FormErrors = Partial<Record<GuestRegistrationField, string | undefined>>;

const props = defineProps<TournamentShowProps>();

const { t, locale } = useI18n();
const routes = useRoutes();
const {
    formatStatus,
    formatPoints,
    formatRecord,
    spotsRemaining,
    isTournamentFull,
    hasStarted,
    shouldShowParticipants,
} = useTournaments();
const { isAuthenticated } = useAuth();
const { error: notifyError, success: notifySuccess } = useNotifications();
// Registering and withdrawing are closed to accounts that must verify their email
const gate = useVerificationGate();
const gateReasonId = useId();
const surveysHeadingId = useId();

const tournamentHasStarted = computed(() => hasStarted(props.tournament));
const showParticipantsList = computed(
    () => shouldShowParticipants(props.tournament) && props.participants.length > 0
);

const statusInfo = computed(() => formatStatus(props.tournament.display_status));

const registrationUpcoming = computed(
    () =>
        props.tournament.display_status === 'registration_upcoming' &&
        props.tournament.registration_opens_at !== null
);

const remainingSpots = computed(() => spotsRemaining(props.tournament));
const isFull = computed(() => isTournamentFull(props.tournament));

const activeStatuses = ['registered', 'confirmed', 'checked_in'];
const isRegistered = computed(
    () => props.userRegistration !== null && activeStatuses.includes(props.userRegistration.status)
);

// The server decides: before the event starts and while the tournament is not running
const canWithdraw = computed(() => {
    return isRegistered.value && props.tournament.can_withdraw;
});

// The server decides who may check in now (registered, window open); the page lives under the same slug
const checkInUrl = computed(() => `/torneos/${props.tournament.slug}/check-in`);

// The register or withdraw button is on screen, so its disabled reason must be too
const showGateReason = computed(
    () => gate.blocked.value && (isRegistered.value ? canWithdraw.value : props.canRegister)
);

// Guest notice: the terms come from the server, the browser only remembers earlier attempts
const { wasRegisteredHere, remember } = useGuestRegistrationMemory();
const registeredFromThisBrowser = computed(() => wasRegisteredHere(props.tournament.id));
const guestLoginUrl = ref<string | null>(null);
const guestFormMessage = ref('');

const formatDeadline = (isoDate: string): string =>
    new Date(isoDate).toLocaleString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const guestConfirmationNotice = computed((): string | null => {
    const terms = props.guestConfirmation;
    if (terms === null) {
        return null;
    }
    if (terms.mode === 'hold' && terms.expires_at !== null) {
        return t('tournaments.public.guest_confirmation_hold', {
            date: formatDeadline(terms.expires_at),
        });
    }
    return t('tournaments.public.guest_confirmation_wait');
});

// Guest registration modal state
const showGuestModal = ref(false);
const guestNameInput = ref<HTMLInputElement | null>(null);
const guestFormProcessing = ref(false);
const guestFormErrors = reactive<FormErrors>({});
const guestForm = reactive({
    guest_name: '',
    guest_email: '',
    gdpr_consent: false,
});

// Withdraw confirmation modal state
const showWithdrawConfirm = ref(false);
const withdrawProcessing = ref(false);

// Focus management for modal
watch(showGuestModal, async (isOpen) => {
    if (isOpen) {
        document.body.style.overflow = 'hidden';
        await nextTick();
        guestNameInput.value?.focus();
    } else {
        document.body.style.overflow = '';
    }
});

const topStandings = computed((): Standing[] => {
    return props.standings.slice(0, 10);
});

const formatDate = (dateString: string | null): string => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const handleRegister = async (): Promise<void> => {
    if (gate.blocked.value) {
        return;
    }

    // If not authenticated and tournament allows guests, show guest modal
    if (!isAuthenticated.value && props.tournament.allow_guests) {
        showGuestModal.value = true;
        return;
    }

    // If not authenticated and no guests allowed, redirect to login
    if (!isAuthenticated.value) {
        router.visit('/iniciar-sesion');
        return;
    }

    // Authenticated user registration via fetch
    try {
        const response = await fetch(`/torneos/${props.tournament.id}/inscripcion`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
        });

        if (isCsrfFailure(response)) {
            notifyError(t('tournaments.public.session_expired'));
            return;
        }

        // Must verify after all (e.g. changed in another tab)
        if (gate.isVerificationRequired(response)) {
            gate.goToNotice();
            return;
        }

        const data: RegistrationJsonResponse = await response.json();

        if (response.ok) {
            // The server words it: registered, or a guest registration moved to the account
            notifySuccess(data.message || t('tournaments.public.registered_successfully'));
            router.reload();
        } else {
            notifyError(data.message || t('tournaments.public.cannot_register'));
        }
    } catch {
        notifyError(t('tournaments.public.registration_error'));
    }
};

const submitGuestRegistration = async (): Promise<void> => {
    guestFormProcessing.value = true;
    delete guestFormErrors.guest_name;
    delete guestFormErrors.guest_email;
    delete guestFormErrors.gdpr_consent;
    guestLoginUrl.value = null;
    guestFormMessage.value = '';

    try {
        const response = await fetch(`/torneos/${props.tournament.id}/inscripcion`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                guest_name: guestForm.guest_name,
                guest_email: guestForm.guest_email,
                gdpr_consent: guestForm.gdpr_consent,
            }),
        });

        if (isCsrfFailure(response)) {
            notifyError(t('tournaments.public.session_expired'));
            return;
        }

        // Signed in meanwhile (e.g. in another tab) with an account that must verify
        if (gate.isVerificationRequired(response)) {
            showGuestModal.value = false;
            gate.goToNotice();
            return;
        }

        const data: RegistrationJsonResponse = await response.json();

        if (response.ok) {
            showGuestModal.value = false;
            guestForm.guest_name = '';
            guestForm.guest_email = '';
            guestForm.gdpr_consent = false;
            remember(props.tournament.id, props.tournament.registration_closes_at);
            notifySuccess(data.message || t('tournaments.public.registered_successfully'));
            router.reload();
        } else if (response.status === 422 && data.errors) {
            // Validation errors
            guestFormErrors.guest_name = data.errors.guest_name?.[0];
            guestFormErrors.guest_email = data.errors.guest_email?.[0];
            guestFormErrors.gdpr_consent = data.errors.gdpr_consent?.[0];
        } else if (response.status === 409 && data.login_url) {
            // The email belongs to an account: the message needs the link to sign in
            guestFormMessage.value = data.message ?? '';
            guestLoginUrl.value = data.login_url;
        } else {
            notifyError(data.message || t('tournaments.public.cannot_register'));
        }
    } catch {
        notifyError(t('tournaments.public.registration_error'));
    } finally {
        guestFormProcessing.value = false;
    }
};

const closeGuestModal = (): void => {
    showGuestModal.value = false;
    guestForm.guest_name = '';
    guestForm.guest_email = '';
    guestForm.gdpr_consent = false;
    delete guestFormErrors.guest_name;
    delete guestFormErrors.guest_email;
    delete guestFormErrors.gdpr_consent;
    guestLoginUrl.value = null;
    guestFormMessage.value = '';
};

const handleKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        closeGuestModal();
    }
};

const handleWithdraw = (): void => {
    if (gate.blocked.value) {
        return;
    }

    showWithdrawConfirm.value = true;
};

const closeWithdrawModal = (): void => {
    showWithdrawConfirm.value = false;
};

const confirmWithdraw = async (): Promise<void> => {
    if (gate.blocked.value) {
        return;
    }

    withdrawProcessing.value = true;

    try {
        const response = await fetch(`/torneos/${props.tournament.id}/inscripcion`, {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
        });

        if (isCsrfFailure(response)) {
            notifyError(t('tournaments.public.session_expired'));
            return;
        }

        // Must verify after all (e.g. changed in another tab)
        if (gate.isVerificationRequired(response)) {
            showWithdrawConfirm.value = false;
            gate.goToNotice();
            return;
        }

        if (response.ok) {
            showWithdrawConfirm.value = false;
            notifySuccess(t('tournaments.public.withdrawn_successfully'));
            router.reload();
        } else {
            const data: RegistrationJsonResponse = await response.json();
            notifyError(data.message || t('tournaments.public.cannot_register'));
        }
    } catch {
        notifyError(t('tournaments.public.registration_error'));
    } finally {
        withdrawProcessing.value = false;
    }
};

const handleWithdrawKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        closeWithdrawModal();
    }
};
</script>

<template>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <!-- Back link -->
        <div class="mb-6">
            <Link
                href="/torneos"
                class="inline-flex items-center text-sm text-[color:var(--color-text-secondary)] hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-surface"
            >
                <ChevronLeft class="mr-1 h-4 w-4" aria-hidden="true" />
                {{ t('common.back') }}
            </Link>
        </div>

        <article class="overflow-hidden rounded-lg bg-surface shadow">
            <div class="p-6 sm:p-8">
                <!-- Header -->
                <div class="mb-6">
                    <div class="mb-4 flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full px-3 py-1 text-sm font-medium"
                            :class="statusBadgeClasses(statusInfo.color)"
                        >
                            {{ tournament.status_label }}
                        </span>
                    </div>

                    <h1 class="mb-4 text-3xl font-bold text-base-primary sm:text-4xl">
                        {{ tournament.name }}
                    </h1>

                    <p v-if="tournament.description" class="text-lg text-base-muted">
                        {{ tournament.description }}
                    </p>
                </div>

                <!-- Tournament Stats -->
                <div class="mb-6 rounded-lg bg-page p-4">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <h3 class="text-sm font-medium text-base-muted">
                                {{ t('tournaments.public.participants_heading') }}
                            </h3>
                            <p class="mt-1 text-2xl font-semibold text-base-primary">
                                {{ tournament.participant_count }}
                                <span
                                    v-if="tournament.max_participants"
                                    class="text-base text-base-muted"
                                >
                                    / {{ tournament.max_participants }}
                                </span>
                            </p>
                        </div>

                        <div v-if="tournamentHasStarted">
                            <h3 class="text-sm font-medium text-base-muted">
                                {{ t('tournaments.public.current_round') }}
                            </h3>
                            <p class="mt-1 text-2xl font-semibold text-base-primary">
                                {{ tournament.current_round }}
                                <span
                                    v-if="tournament.max_rounds"
                                    class="text-base text-base-muted"
                                >
                                    / {{ tournament.max_rounds }}
                                </span>
                            </p>
                        </div>

                        <div v-if="remainingSpots !== null">
                            <h3 class="text-sm font-medium text-base-muted">
                                {{ t('tournaments.public.spots_available') }}
                            </h3>
                            <p
                                class="mt-1 text-2xl font-semibold"
                                :class="isFull ? 'text-error' : 'text-success'"
                            >
                                {{ remainingSpots }}
                            </p>
                        </div>

                        <div v-if="tournament.started_at">
                            <h3 class="text-sm font-medium text-base-muted">
                                {{ t('tournaments.public.started_at') }}
                            </h3>
                            <p class="mt-1 text-sm text-base-primary">
                                {{ formatDate(tournament.started_at) }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Registration not open yet: say when it opens -->
                <div
                    v-if="registrationUpcoming && !isRegistered"
                    class="mb-6 rounded-lg border border-primary bg-primary-light p-4"
                >
                    <h3 class="font-semibold text-primary">
                        {{ t('tournaments.public.registration_upcoming') }}
                    </h3>
                    <p class="mt-1 text-sm text-primary">
                        {{
                            t('tournaments.public.registration_opens_on', {
                                date: formatDate(tournament.registration_opens_at),
                            })
                        }}
                    </p>
                </div>

                <!-- Registration Section -->
                <div
                    v-if="tournament.is_registration_open || isRegistered"
                    class="mb-6 rounded-lg border p-4"
                    :class="
                        isRegistered
                            ? 'border-success bg-success-light'
                            : 'border-primary bg-primary-light'
                    "
                >
                    <div v-if="isRegistered">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-semibold text-success">
                                    {{ t('tournaments.public.already_registered') }}
                                </h3>
                                <p class="mt-1 text-sm text-success">
                                    {{
                                        t('tournaments.public.registered_status', {
                                            status: userRegistration?.status_label,
                                        })
                                    }}
                                </p>
                            </div>
                            <BaseButton
                                v-if="canWithdraw"
                                variant="danger"
                                size="sm"
                                :disabled="gate.blocked.value"
                                :aria-describedby="gate.blocked.value ? gateReasonId : undefined"
                                @click="handleWithdraw"
                            >
                                {{ t('tournaments.public.withdraw') }}
                            </BaseButton>
                        </div>

                        <Link
                            v-if="canCheckIn"
                            :href="checkInUrl"
                            class="mt-4 inline-flex min-h-[44px] w-full items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-base font-semibold text-white shadow-sm hover:bg-primary-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:w-auto"
                        >
                            {{ t('tournaments.public.check_in_now') }}
                        </Link>
                    </div>

                    <div v-else-if="canRegister" class="flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold text-primary">
                                {{ t('tournaments.public.registration_open') }}
                            </h3>
                            <p v-if="remainingSpots !== null" class="mt-1 text-sm text-primary">
                                {{
                                    t('tournaments.public.spots_remaining', {
                                        count: remainingSpots,
                                    })
                                }}
                            </p>
                        </div>
                        <BaseButton
                            variant="primary"
                            :disabled="gate.blocked.value"
                            :aria-describedby="gate.blocked.value ? gateReasonId : undefined"
                            @click="handleRegister"
                        >
                            {{ t('tournaments.public.register') }}
                        </BaseButton>
                    </div>

                    <div v-else>
                        <h3 class="font-semibold text-primary">
                            {{ t('tournaments.public.cannot_register') }}
                        </h3>
                        <p class="mt-1 text-sm text-primary">
                            {{ t('tournaments.public.login_to_register') }}
                        </p>
                    </div>

                    <p
                        v-if="showGateReason"
                        :id="gateReasonId"
                        class="mt-3 rounded-md bg-warning-light p-3 text-sm text-base-primary"
                    >
                        {{ isRegistered ? gate.reason('withdraw') : gate.reason('register') }}
                        <Link
                            :href="gate.noticeUrl"
                            class="font-semibold underline underline-offset-2 hover:no-underline"
                        >
                            {{ gate.linkLabel.value }}
                            <span aria-hidden="true">→</span>
                        </Link>
                    </p>
                </div>

                <!-- Surveys: plain links, their pages belong to another module -->
                <section
                    v-if="surveyLinks"
                    class="mb-6 rounded-lg border border-default p-4"
                    :aria-labelledby="surveysHeadingId"
                >
                    <h2 :id="surveysHeadingId" class="mb-3 font-semibold text-base-primary">
                        {{ t('tournaments.surveys.heading') }}
                    </h2>
                    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        <a
                            v-if="surveyLinks.manageUrl"
                            :href="surveyLinks.manageUrl"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-md border border-default px-4 py-2 text-sm font-medium text-base-primary transition-colors hover:bg-muted focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface"
                        >
                            {{ t('tournaments.surveys.manage') }}
                        </a>
                        <a
                            v-for="survey in surveyLinks.surveys"
                            :key="survey.url"
                            :href="survey.url"
                            class="inline-flex min-h-[44px] items-center justify-center rounded-md px-4 py-2 text-center text-sm font-medium break-words transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface"
                            :class="
                                survey.action === 'respond'
                                    ? 'bg-primary text-white hover:bg-primary-700'
                                    : 'border border-default text-base-primary hover:bg-muted'
                            "
                        >
                            {{
                                survey.action === 'respond'
                                    ? t('tournaments.surveys.respond', { title: survey.title })
                                    : t('tournaments.surveys.results', { title: survey.title })
                            }}
                        </a>
                    </div>
                </section>

                <!-- Current Round -->
                <div
                    v-if="currentRound"
                    class="mb-6 rounded-lg border border-info/40 bg-info-light p-4"
                >
                    <h2 class="mb-2 font-semibold text-base-primary">
                        {{
                            t('tournaments.public.current_round_title', {
                                round: currentRound.round_number,
                            })
                        }}
                    </h2>
                    <div class="flex items-center gap-4 text-sm text-base-secondary">
                        <span>
                            {{
                                t('tournaments.public.matches_completed', {
                                    completed: currentRound.completed_match_count,
                                    total: currentRound.match_count,
                                })
                            }}
                        </span>
                        <span class="font-medium">
                            {{ currentRound.completion_percentage.toFixed(0) }}%
                        </span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-base-secondary/10">
                        <div
                            class="h-full bg-info transition-all"
                            :style="{ width: `${currentRound.completion_percentage}%` }"
                        />
                    </div>
                </div>

                <!-- Participants List (during registration) -->
                <div v-if="showParticipantsList" class="mb-6">
                    <h2 class="mb-4 text-lg font-semibold text-base-primary">
                        {{ t('tournaments.public.registered_participants') }}
                    </h2>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="participant in participants"
                            :key="participant.id"
                            class="inline-flex items-center rounded-full bg-base-secondary/10 px-3 py-1 text-sm font-medium text-base-secondary"
                        >
                            {{ participant.display_name }}
                        </span>
                    </div>
                </div>

                <!-- Top Standings Preview (after tournament starts) -->
                <div v-if="tournamentHasStarted && topStandings.length > 0" class="mb-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-base-primary">
                            {{ t('tournaments.public.standings') }}
                        </h2>
                        <Link
                            :href="`/torneos/${tournament.slug}/clasificacion`"
                            class="text-sm text-primary hover:text-primary"
                        >
                            {{ t('tournaments.public.view_all_standings') }}
                        </Link>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-default">
                            <thead>
                                <tr
                                    class="text-left text-xs font-medium uppercase tracking-wider text-base-muted"
                                >
                                    <th class="px-3 py-2">#</th>
                                    <th class="px-3 py-2">{{ t('tournaments.public.player') }}</th>
                                    <th class="px-3 py-2 text-right">
                                        {{ t('tournaments.public.points') }}
                                    </th>
                                    <th class="hidden px-3 py-2 text-right sm:table-cell">
                                        {{ t('tournaments.public.record') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-default">
                                <tr
                                    v-for="standing in topStandings"
                                    :key="standing.participant_id"
                                    class="text-base-primary"
                                >
                                    <td class="px-3 py-2 font-medium">{{ standing.rank }}</td>
                                    <td class="px-3 py-2">{{ standing.participant_name }}</td>
                                    <td class="px-3 py-2 text-right font-semibold">
                                        {{ formatPoints(standing.points) }}
                                    </td>
                                    <td
                                        class="hidden px-3 py-2 text-right text-sm text-base-secondary sm:table-cell"
                                    >
                                        {{ formatRecord(standing) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Navigation Links -->
                <div
                    v-if="tournamentHasStarted"
                    class="flex flex-wrap gap-4 border-t border-default pt-6"
                >
                    <!-- Links styled as the core's secondary BaseButton: a button inside a link
                         would be two interactive elements for one action -->
                    <Link
                        :href="`/torneos/${tournament.slug}/clasificacion`"
                        class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md bg-muted px-4 py-2 text-base font-medium text-base-primary transition-colors hover:bg-base-secondary/20 focus:outline-none focus:ring-2 focus:ring-base-muted focus:ring-offset-2 focus:ring-offset-surface"
                    >
                        {{ t('tournaments.public.standings') }}
                    </Link>
                    <Link
                        :href="`/torneos/${tournament.slug}/rondas`"
                        class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-md bg-muted px-4 py-2 text-base font-medium text-base-primary transition-colors hover:bg-base-secondary/20 focus:outline-none focus:ring-2 focus:ring-base-muted focus:ring-offset-2 focus:ring-offset-surface"
                    >
                        {{ t('tournaments.public.rounds') }}
                    </Link>
                </div>
            </div>
        </article>
    </div>

    <!-- Guest Registration Modal -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            leave-active-class="transition-opacity duration-150 ease-in"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="showGuestModal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="'guest-modal-title'"
                @keydown="handleKeydown"
            >
                <!-- Modal backdrop (click to close) -->
                <div class="absolute inset-0" @click="closeGuestModal" />

                <!-- Modal content -->
                <div class="relative w-full max-w-md rounded-lg bg-surface p-6 shadow-xl">
                    <!-- Close button -->
                    <button
                        type="button"
                        class="absolute right-4 top-4 text-[color:var(--color-text-secondary)] hover:text-primary"
                        :aria-label="t('common.close')"
                        @click="closeGuestModal"
                    >
                        <X class="h-5 w-5" aria-hidden="true" />
                    </button>

                    <!-- Modal header -->
                    <h2 id="guest-modal-title" class="mb-4 text-lg font-semibold text-base-primary">
                        {{ t('tournaments.public.guest_registration') }}
                    </h2>

                    <!-- Form -->
                    <form class="space-y-4" @submit.prevent="submitGuestRegistration">
                        <!-- Name field -->
                        <div>
                            <label
                                for="guest-name"
                                class="mb-1 block text-sm font-medium text-base-secondary"
                            >
                                {{ t('tournaments.public.guest_name') }}
                                <span class="text-error" aria-label="required">*</span>
                            </label>
                            <input
                                id="guest-name"
                                ref="guestNameInput"
                                v-model="guestForm.guest_name"
                                type="text"
                                required
                                :disabled="guestFormProcessing"
                                :aria-invalid="!!guestFormErrors.guest_name"
                                :aria-describedby="
                                    guestFormErrors.guest_name ? 'guest-name-error' : undefined
                                "
                                class="w-full rounded-md border border-default px-4 py-2 shadow-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:bg-base-secondary/10"
                                :class="{
                                    'border-error focus:border-error focus:ring-error':
                                        guestFormErrors.guest_name,
                                }"
                            />
                            <p
                                v-if="guestFormErrors.guest_name"
                                id="guest-name-error"
                                class="mt-1 text-sm text-error"
                                role="alert"
                            >
                                {{ guestFormErrors.guest_name }}
                            </p>
                        </div>

                        <!-- Email field -->
                        <div>
                            <label
                                for="guest-email"
                                class="mb-1 block text-sm font-medium text-base-secondary"
                            >
                                {{ t('tournaments.public.guest_email') }}
                                <span class="text-error" aria-label="required">*</span>
                            </label>
                            <input
                                id="guest-email"
                                v-model="guestForm.guest_email"
                                type="email"
                                required
                                :disabled="guestFormProcessing"
                                :aria-invalid="!!guestFormErrors.guest_email"
                                :aria-describedby="
                                    guestFormErrors.guest_email ? 'guest-email-error' : undefined
                                "
                                class="w-full rounded-md border border-default px-4 py-2 shadow-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:bg-base-secondary/10"
                                :class="{
                                    'border-error focus:border-error focus:ring-error':
                                        guestFormErrors.guest_email,
                                }"
                            />
                            <p
                                v-if="guestFormErrors.guest_email"
                                id="guest-email-error"
                                class="mt-1 text-sm text-error"
                                role="alert"
                            >
                                {{ guestFormErrors.guest_email }}
                            </p>
                        </div>

                        <!-- GDPR Consent -->
                        <div>
                            <div class="flex items-start gap-2">
                                <input
                                    id="guest-gdpr"
                                    v-model="guestForm.gdpr_consent"
                                    type="checkbox"
                                    required
                                    :disabled="guestFormProcessing"
                                    :aria-invalid="!!guestFormErrors.gdpr_consent"
                                    :aria-describedby="
                                        guestFormErrors.gdpr_consent
                                            ? 'guest-gdpr-error'
                                            : undefined
                                    "
                                    class="mt-1 h-4 w-4 rounded border-strong text-primary focus:ring-primary-500 disabled:cursor-not-allowed"
                                />
                                <label for="guest-gdpr" class="text-sm text-base-muted">
                                    {{ t('tournaments.public.gdpr_consent') }}
                                    <a
                                        :href="routes.legal.privacy"
                                        target="_blank"
                                        class="text-primary underline hover:no-underline"
                                    >
                                        {{ t('tournaments.public.privacy_policy') }}
                                    </a>
                                    <span class="text-error" aria-label="required">*</span>
                                </label>
                            </div>
                            <p
                                v-if="guestFormErrors.gdpr_consent"
                                id="guest-gdpr-error"
                                class="mt-1 text-sm text-error"
                                role="alert"
                            >
                                {{ guestFormErrors.gdpr_consent }}
                            </p>
                        </div>

                        <!-- Before sending: what happens next, and whether this browser already registered -->
                        <p
                            v-if="registeredFromThisBrowser"
                            class="rounded-md bg-warning-light p-3 text-sm text-base-primary"
                            role="status"
                        >
                            {{ t('tournaments.public.already_registered_here') }}
                        </p>
                        <p v-if="guestConfirmationNotice" class="text-sm text-base-muted">
                            {{ guestConfirmationNotice }}
                        </p>
                        <p v-if="guestLoginUrl" class="text-sm text-error" role="alert">
                            {{ guestFormMessage }}
                            <a :href="guestLoginUrl" class="font-medium underline">{{
                                t('tournaments.public.sign_in')
                            }}</a>
                        </p>

                        <!-- Actions -->
                        <div class="mt-6 flex justify-end gap-3">
                            <BaseButton
                                type="button"
                                variant="secondary"
                                :disabled="guestFormProcessing"
                                @click="closeGuestModal"
                            >
                                {{ t('common.cancel') }}
                            </BaseButton>
                            <BaseButton
                                type="submit"
                                variant="primary"
                                :loading="guestFormProcessing"
                                :disabled="guestFormProcessing"
                            >
                                {{ t('tournaments.public.register') }}
                            </BaseButton>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- Withdraw Confirmation Modal -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            leave-active-class="transition-opacity duration-150 ease-in"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="showWithdrawConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="'withdraw-modal-title'"
                @keydown="handleWithdrawKeydown"
            >
                <!-- Modal backdrop (click to close) -->
                <div class="absolute inset-0" @click="closeWithdrawModal" />

                <!-- Modal content -->
                <div class="relative w-full max-w-md rounded-lg bg-surface p-6 shadow-xl">
                    <!-- Warning icon -->
                    <div
                        class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-error-light"
                    >
                        <TriangleAlert class="h-6 w-6 text-error" aria-hidden="true" />
                    </div>

                    <!-- Modal header -->
                    <h2
                        id="withdraw-modal-title"
                        class="mt-4 text-center text-lg font-semibold text-base-primary"
                    >
                        {{ t('tournaments.public.withdraw_title') }}
                    </h2>

                    <!-- Confirmation message -->
                    <p class="mt-2 text-center text-sm text-base-muted">
                        {{ t('tournaments.public.withdraw_confirm') }}
                    </p>

                    <!-- Actions -->
                    <div class="mt-6 flex justify-center gap-3">
                        <BaseButton
                            type="button"
                            variant="secondary"
                            :disabled="withdrawProcessing"
                            @click="closeWithdrawModal"
                        >
                            {{ t('common.cancel') }}
                        </BaseButton>
                        <BaseButton
                            type="button"
                            variant="danger"
                            :loading="withdrawProcessing"
                            :disabled="withdrawProcessing"
                            @click="confirmWithdraw"
                        >
                            {{ t('tournaments.public.withdraw') }}
                        </BaseButton>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
