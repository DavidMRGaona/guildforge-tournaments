<script setup lang="ts">
import { CircleCheck, RefreshCw, TriangleAlert, Trophy, Users, X } from '@lucide/vue';
import { computed, ref, reactive, watch, nextTick, useId } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useAuth } from '@/composables/useAuth';
import { useVerificationGate } from '@/composables/useVerificationGate';
import { useNotifications } from '@/composables/useNotifications';
import { useRoutes } from '@/composables/useRoutes';
import { csrfHeaders, isCsrfFailure } from '@/utils/csrf';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useTournaments } from '../composables/useTournaments';
import { useGuestRegistrationMemory } from '../composables/useGuestRegistrationMemory';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import { statusBadgeClasses } from '../utils/statusColors';
import type {
    EventTournamentSectionProps,
    GuestRegistrationField,
    RegistrationJsonResponse,
} from '../types/tournaments';

// A missing message in a 422 clears the field error, hence `undefined`
type FormErrors = Partial<Record<GuestRegistrationField, string | undefined>>;

const props = defineProps<EventTournamentSectionProps>();

const { t, locale } = useI18n();
const routes = useRoutes();
const { getStatusColor } = useTournaments();
const { isAuthenticated } = useAuth();
const { error: notifyError, success: notifySuccess } = useNotifications();
// Registering and withdrawing are closed to accounts that must verify their email
const gate = useVerificationGate();
const gateReasonId = useId();

const activeStatuses = ['registered', 'confirmed', 'checked_in'];
const isRegistered = computed(
    () => props.userRegistration !== null && activeStatuses.includes(props.userRegistration.status)
);

const spotsRemaining = computed(() => {
    if (props.tournament === null || props.tournament.max_participants === null) {
        return null;
    }
    return Math.max(0, props.tournament.max_participants - props.tournament.participant_count);
});

const isFull = computed(() => {
    if (props.tournament === null || props.tournament.max_participants === null) {
        return false;
    }
    return props.tournament.participant_count >= props.tournament.max_participants;
});

// The server decides: before the event starts and while the tournament is not running
const canWithdraw = computed(() => {
    if (props.tournament === null) {
        return false;
    }
    return isRegistered.value && props.tournament.can_withdraw;
});

const showRegisterButton = computed(
    () =>
        props.tournament !== null &&
        !isRegistered.value &&
        props.canRegister &&
        props.tournament.is_registration_open &&
        !isFull.value
);

// The register or withdraw button is on screen, so its disabled reason must be too
const showGateReason = computed(
    () => gate.blocked.value && (showRegisterButton.value || canWithdraw.value)
);

// Guest notice: the terms come from the server, the browser only remembers earlier attempts
const { wasRegisteredHere, remember } = useGuestRegistrationMemory();
const registeredFromThisBrowser = computed(() => wasRegisteredHere(props.tournament?.id ?? ''));
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

// Same routes and responses as the tournament page (Tournaments/Show.vue)
const registrationUrl = (tournamentId: string): string => `/torneos/${tournamentId}/inscripcion`;

const handleRegister = async (): Promise<void> => {
    if (gate.blocked.value) {
        return;
    }

    if (props.tournament === null) {
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
        const response = await fetch(registrationUrl(props.tournament.id), {
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

const resetGuestForm = (): void => {
    guestForm.guest_name = '';
    guestForm.guest_email = '';
    guestForm.gdpr_consent = false;
};

const clearGuestFormErrors = (): void => {
    delete guestFormErrors.guest_name;
    delete guestFormErrors.guest_email;
    delete guestFormErrors.gdpr_consent;
    guestLoginUrl.value = null;
    guestFormMessage.value = '';
};

const submitGuestRegistration = async (): Promise<void> => {
    if (props.tournament === null) {
        return;
    }

    guestFormProcessing.value = true;
    clearGuestFormErrors();

    try {
        const response = await fetch(registrationUrl(props.tournament.id), {
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
            resetGuestForm();
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
    resetGuestForm();
    clearGuestFormErrors();
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

    if (props.tournament === null) {
        return;
    }

    withdrawProcessing.value = true;

    try {
        const response = await fetch(registrationUrl(props.tournament.id), {
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
            notifyError(data.message || t('tournaments.public.cannot_withdraw'));
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
    <div v-if="tournament" class="rounded-lg border border-primary-500/30 bg-primary-500/10 p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
                <!-- Tournament Info Header -->
                <div class="mb-3 flex items-center gap-2">
                    <!-- Trophy icon -->
                    <Trophy class="h-5 w-5 text-primary" aria-hidden="true" />
                    <h3 class="font-semibold text-base-primary">
                        {{ t('tournaments.public.event_tournament') }}
                    </h3>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="statusBadgeClasses(getStatusColor(tournament.display_status))"
                    >
                        {{ tournament.status_label }}
                    </span>
                </div>

                <!-- Tournament Stats -->
                <div class="mb-3 flex flex-wrap gap-4 text-sm text-base-secondary">
                    <span class="flex items-center gap-1">
                        <Users class="h-4 w-4" aria-hidden="true" />
                        {{ tournament.participant_count }}
                        <template v-if="tournament.max_participants">
                            / {{ tournament.max_participants }}
                        </template>
                        {{ t('tournaments.public.participants') }}
                    </span>

                    <span v-if="tournament.current_round > 0" class="flex items-center gap-1">
                        <RefreshCw class="h-4 w-4" aria-hidden="true" />
                        {{
                            t('tournaments.public.round_label', {
                                current: tournament.current_round,
                                max: tournament.max_rounds || '?',
                            })
                        }}
                    </span>
                </div>

                <!-- Registration Status -->
                <div v-if="isRegistered" class="mb-3">
                    <span class="inline-flex items-center gap-1 text-sm font-medium text-success">
                        <CircleCheck class="h-4 w-4" aria-hidden="true" />
                        {{ t('tournaments.public.already_registered') }}
                    </span>
                </div>

                <!-- Spots Available Info -->
                <div
                    v-else-if="tournament.is_registration_open && spotsRemaining !== null"
                    class="mb-3"
                >
                    <span class="text-sm" :class="isFull ? 'text-error' : 'text-primary'">
                        {{
                            isFull
                                ? t('tournaments.public.tournament_full')
                                : t('tournaments.public.spots_remaining', { count: spotsRemaining })
                        }}
                    </span>
                </div>

                <!-- Link to Tournament Page -->
                <Link
                    :href="`/torneos/${tournament.slug}`"
                    class="text-sm text-primary hover:underline"
                >
                    {{ t('tournaments.public.view_tournament') }}
                    <span aria-hidden="true">&rarr;</span>
                </Link>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-shrink-0 flex-col gap-2">
                <!-- Register Button -->
                <BaseButton
                    v-if="showRegisterButton"
                    variant="primary"
                    size="sm"
                    :disabled="gate.blocked.value"
                    :aria-describedby="gate.blocked.value ? gateReasonId : undefined"
                    @click="handleRegister"
                >
                    {{ t('tournaments.public.register') }}
                </BaseButton>

                <!-- Withdraw Button -->
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
        </div>

        <p
            v-if="showGateReason"
            :id="gateReasonId"
            class="mt-3 rounded-md bg-warning-light p-3 text-sm text-base-primary"
        >
            {{ canWithdraw ? gate.reason('withdraw') : gate.reason('register') }}
            <Link
                :href="gate.noticeUrl"
                class="font-semibold underline underline-offset-2 hover:no-underline"
            >
                {{ gate.linkLabel.value }}
                <span aria-hidden="true">→</span>
            </Link>
        </p>
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
                    <form @submit.prevent="submitGuestRegistration" class="space-y-4">
                        <!-- Name field -->
                        <div>
                            <label
                                for="guest-name"
                                class="block text-sm font-medium text-base-secondary mb-1"
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
                                class="w-full px-4 py-2 border border-default rounded-md shadow-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 disabled:bg-base-secondary/10 disabled:cursor-not-allowed transition-colors"
                                :class="{
                                    'border-error focus:ring-error focus:border-error':
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
                                class="block text-sm font-medium text-base-secondary mb-1"
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
                                class="w-full px-4 py-2 border border-default rounded-md shadow-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 disabled:bg-base-secondary/10 disabled:cursor-not-allowed transition-colors"
                                :class="{
                                    'border-error focus:ring-error focus:border-error':
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
                                    id="event-guest-gdpr"
                                    v-model="guestForm.gdpr_consent"
                                    type="checkbox"
                                    required
                                    :disabled="guestFormProcessing"
                                    :aria-invalid="!!guestFormErrors.gdpr_consent"
                                    :aria-describedby="
                                        guestFormErrors.gdpr_consent
                                            ? 'event-guest-gdpr-error'
                                            : undefined
                                    "
                                    class="mt-1 h-4 w-4 rounded border-default text-primary focus:ring-primary-500 disabled:cursor-not-allowed"
                                />
                                <label for="event-guest-gdpr" class="text-sm text-base-muted">
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
                                id="event-guest-gdpr-error"
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
