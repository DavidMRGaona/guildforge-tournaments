<script setup lang="ts">
import { computed, ref, reactive, watch, nextTick } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentShowProps, Standing } from '../../types/tournaments';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useSeo } from '@/composables/useSeo';
import { useTournaments } from '../../composables/useTournaments';
import { useAuth } from '@/composables/useAuth';
import { useNotifications } from '@/composables/useNotifications';

interface FormErrors {
    guest_name?: string;
    guest_email?: string;
    gdpr_consent?: string;
}

const props = defineProps<TournamentShowProps>();

const { t, locale } = useI18n();
const { formatStatus, formatPoints, formatRecord, spotsRemaining, isTournamentFull, hasStarted, shouldShowParticipants } = useTournaments();
const { isAuthenticated } = useAuth();
const { error: notifyError, success: notifySuccess } = useNotifications();

const tournamentHasStarted = computed(() => hasStarted(props.tournament));
const showParticipantsList = computed(() => shouldShowParticipants(props.tournament) && props.participants.length > 0);

useSeo({
    title: props.tournament.name,
    description: props.tournament.description || t('tournaments.description'),
    type: 'article',
    canonical: `/torneos/${props.tournament.slug}`,
});

const statusInfo = computed(() => formatStatus(props.tournament.status));

const remainingSpots = computed(() => spotsRemaining(props.tournament));
const isFull = computed(() => isTournamentFull(props.tournament));

const activeStatuses = ['registered', 'confirmed', 'checked_in'];
const isRegistered = computed(() =>
    props.userRegistration !== null && activeStatuses.includes(props.userRegistration.status)
);

const canWithdraw = computed(() => {
    return isRegistered.value && props.tournament.is_registration_open;
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

const getCsrfToken = (): string => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
};

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
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const handleRegister = async (): Promise<void> => {
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
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
        });

        if (response.ok) {
            notifySuccess(t('tournaments.public.registered_successfully'));
            router.reload();
        } else {
            const data = await response.json();
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

    try {
        const response = await fetch(`/torneos/${props.tournament.id}/inscripcion`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                guest_name: guestForm.guest_name,
                guest_email: guestForm.guest_email,
                gdpr_consent: guestForm.gdpr_consent,
            }),
        });

        const data = await response.json();

        if (response.ok) {
            showGuestModal.value = false;
            guestForm.guest_name = '';
            guestForm.guest_email = '';
            guestForm.gdpr_consent = false;
            notifySuccess(t('tournaments.public.registered_successfully'));
            router.reload();
        } else if (response.status === 422 && data.errors) {
            // Validation errors
            guestFormErrors.guest_name = data.errors.guest_name?.[0];
            guestFormErrors.guest_email = data.errors.guest_email?.[0];
            guestFormErrors.gdpr_consent = data.errors.gdpr_consent?.[0];
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
};

const handleKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        closeGuestModal();
    }
};

const handleWithdraw = (): void => {
    showWithdrawConfirm.value = true;
};

const closeWithdrawModal = (): void => {
    showWithdrawConfirm.value = false;
};

const confirmWithdraw = async (): Promise<void> => {
    withdrawProcessing.value = true;

    try {
        const response = await fetch(`/torneos/${props.tournament.id}/inscripcion`, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
        });

        if (response.ok) {
            showWithdrawConfirm.value = false;
            notifySuccess(t('tournaments.public.withdrawn_successfully'));
            router.reload();
        } else {
            const data = await response.json();
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
    <DefaultLayout>
        <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Back link -->
            <div class="mb-6">
                <Link
                    href="/eventos"
                    class="inline-flex items-center text-sm text-base-muted hover:text-base-secondary focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 dark:focus:ring-offset-stone-900"
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
                            d="M15 19l-7-7 7-7"
                        />
                    </svg>
                    {{ t('common.back') }}
                </Link>
            </div>

            <article
                class="overflow-hidden rounded-lg bg-surface shadow dark:shadow-stone-900/50"
            >
                <div class="p-6 sm:p-8">
                    <!-- Header -->
                    <div class="mb-6">
                        <div class="mb-4 flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full px-3 py-1 text-sm font-medium"
                                :class="{
                                    'bg-muted text-base-secondary': statusInfo.color === 'gray',
                                    'bg-success-light text-success': statusInfo.color === 'green',
                                    'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400': statusInfo.color === 'yellow',
                                    'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': statusInfo.color === 'blue',
                                    'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400': statusInfo.color === 'purple',
                                    'bg-error-light text-error': statusInfo.color === 'red',
                                }"
                            >
                                {{ tournament.status_label }}
                            </span>
                        </div>

                        <h1
                            class="mb-4 text-3xl font-bold text-base-primary sm:text-4xl"
                        >
                            {{ tournament.name }}
                        </h1>

                        <p
                            v-if="tournament.description"
                            class="text-lg text-base-muted"
                        >
                            {{ tournament.description }}
                        </p>
                    </div>

                    <!-- Tournament Stats -->
                    <div class="mb-6 rounded-lg bg-muted p-4">
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <div>
                                <h3 class="text-sm font-medium text-base-muted">
                                    {{ t('tournaments.public.participants') }}
                                </h3>
                                <p class="mt-1 text-2xl font-semibold text-base-primary">
                                    {{ tournament.participant_count }}
                                    <span v-if="tournament.max_participants" class="text-base text-stone-500">
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
                                    <span v-if="tournament.max_rounds" class="text-base text-stone-500">
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

                    <!-- Registration Section -->
                    <div
                        v-if="tournament.is_registration_open || isRegistered"
                        class="mb-6 rounded-lg border p-4"
                        :class="isRegistered
                            ? 'border-success bg-success-light'
                            : 'border-primary bg-primary-light'"
                    >
                        <div v-if="isRegistered" class="flex items-center justify-between">
                            <div>
                                <h3 class="font-semibold text-success">
                                    {{ t('tournaments.public.already_registered') }}
                                </h3>
                                <p class="mt-1 text-sm text-success">
                                    {{ t('tournaments.public.registered_status', { status: userRegistration?.status_label }) }}
                                </p>
                            </div>
                            <BaseButton
                                v-if="canWithdraw"
                                variant="danger"
                                size="sm"
                                @click="handleWithdraw"
                            >
                                {{ t('tournaments.public.withdraw') }}
                            </BaseButton>
                        </div>

                        <div v-else-if="canRegister" class="flex items-center justify-between">
                            <div>
                                <h3 class="font-semibold text-primary">
                                    {{ t('tournaments.public.registration_open') }}
                                </h3>
                                <p v-if="remainingSpots !== null" class="mt-1 text-sm text-primary">
                                    {{ t('tournaments.public.spots_remaining', { count: remainingSpots }) }}
                                </p>
                            </div>
                            <BaseButton variant="primary" @click="handleRegister">
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
                    </div>

                    <!-- Current Round -->
                    <div
                        v-if="currentRound"
                        class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/30"
                    >
                        <h2 class="mb-2 font-semibold text-blue-900 dark:text-blue-100">
                            {{ t('tournaments.public.current_round_title', { round: currentRound.round_number }) }}
                        </h2>
                        <div class="flex items-center gap-4 text-sm text-blue-700 dark:text-blue-300">
                            <span>
                                {{ t('tournaments.public.matches_completed', {
                                    completed: currentRound.completed_match_count,
                                    total: currentRound.match_count
                                }) }}
                            </span>
                            <span class="font-medium">
                                {{ currentRound.completion_percentage.toFixed(0) }}%
                            </span>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-blue-200 dark:bg-blue-900">
                            <div
                                class="h-full bg-blue-500 transition-all"
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
                                class="inline-flex items-center rounded-full bg-muted px-3 py-1 text-sm font-medium text-base-secondary"
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
                                    <tr class="text-left text-xs font-medium uppercase tracking-wider text-base-muted">
                                        <th class="px-3 py-2">#</th>
                                        <th class="px-3 py-2">{{ t('tournaments.public.player') }}</th>
                                        <th class="px-3 py-2 text-right">{{ t('tournaments.public.points') }}</th>
                                        <th class="hidden px-3 py-2 text-right sm:table-cell">{{ t('tournaments.public.record') }}</th>
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
                                        <td class="px-3 py-2 text-right font-semibold">{{ formatPoints(standing.points) }}</td>
                                        <td class="hidden px-3 py-2 text-right text-sm text-stone-500 sm:table-cell dark:text-stone-400">
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
                        <Link :href="`/torneos/${tournament.slug}/clasificacion`">
                            <BaseButton variant="secondary">
                                {{ t('tournaments.public.standings') }}
                            </BaseButton>
                        </Link>
                        <Link :href="`/torneos/${tournament.slug}/rondas`">
                            <BaseButton variant="secondary">
                                {{ t('tournaments.public.rounds') }}
                            </BaseButton>
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
                    <div
                        class="absolute inset-0"
                        @click="closeGuestModal"
                    />

                    <!-- Modal content -->
                    <div
                        class="relative w-full max-w-md rounded-lg bg-surface p-6 shadow-xl"
                    >
                        <!-- Close button -->
                        <button
                            type="button"
                            class="absolute right-4 top-4 text-base-muted hover:text-base-secondary"
                            :aria-label="t('common.close')"
                            @click="closeGuestModal"
                        >
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>

                        <!-- Modal header -->
                        <h2
                            id="guest-modal-title"
                            class="mb-4 text-lg font-semibold text-base-primary"
                        >
                            {{ t('tournaments.public.guest_registration') }}
                        </h2>

                        <!-- Form -->
                        <form @submit.prevent="submitGuestRegistration" class="space-y-4">
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
                                    :aria-describedby="guestFormErrors.guest_name ? 'guest-name-error' : undefined"
                                    class="w-full rounded-md border border-default px-4 py-2 shadow-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:bg-muted dark:border-stone-600 dark:bg-stone-700 dark:text-stone-100 dark:placeholder-stone-400 dark:disabled:bg-stone-800 dark:disabled:text-stone-500"
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
                                    :aria-describedby="guestFormErrors.guest_email ? 'guest-email-error' : undefined"
                                    class="w-full rounded-md border border-default px-4 py-2 shadow-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary disabled:cursor-not-allowed disabled:bg-muted dark:border-stone-600 dark:bg-stone-700 dark:text-stone-100 dark:placeholder-stone-400 dark:disabled:bg-stone-800 dark:disabled:text-stone-500"
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
                                        :aria-describedby="guestFormErrors.gdpr_consent ? 'guest-gdpr-error' : undefined"
                                        class="mt-1 h-4 w-4 rounded border-stone-300 text-primary focus:ring-primary-500 disabled:cursor-not-allowed dark:border-stone-600 dark:bg-stone-700"
                                    />
                                    <label
                                        for="guest-gdpr"
                                        class="text-sm text-base-muted"
                                    >
                                        {{ t('tournaments.public.gdpr_consent') }}
                                        <a
                                            href="/politica-de-privacidad"
                                            target="_blank"
                                            class="text-primary underline hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300"
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
                    <div
                        class="absolute inset-0"
                        @click="closeWithdrawModal"
                    />

                    <!-- Modal content -->
                    <div
                        class="relative w-full max-w-md rounded-lg bg-surface p-6 shadow-xl"
                    >
                        <!-- Warning icon -->
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-error-light">
                            <svg
                                class="h-6 w-6 text-error"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                />
                            </svg>
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
    </DefaultLayout>
</template>
