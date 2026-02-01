<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { useAuth } from '@/composables/useAuth';
import BaseButton from '@/components/ui/BaseButton.vue';
import type { EventTournamentSectionProps } from '../types/tournaments';

const props = defineProps<EventTournamentSectionProps>();

const { t } = useI18n();
const { isAuthenticated } = useAuth();

const activeStatuses = ['registered', 'confirmed', 'checked_in'];
const isRegistered = computed(() =>
    props.userRegistration !== null && activeStatuses.includes(props.userRegistration.status)
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

const canWithdraw = computed(() => {
    if (props.tournament === null) {
        return false;
    }
    return isRegistered.value && props.tournament.is_registration_open;
});

// Guest registration modal state
const showGuestModal = ref(false);
const guestNameInput = ref<HTMLInputElement | null>(null);
const guestForm = useForm({
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

const handleRegister = (): void => {
    if (props.tournament === null) {
        return;
    }

    // If not authenticated and tournament allows guests, show guest modal
    if (!isAuthenticated.value && props.tournament.allow_guests) {
        showGuestModal.value = true;
        return;
    }

    // Otherwise, attempt regular registration (will redirect to login if not authenticated)
    router.post(`/api/torneos/${props.tournament.id}/inscripcion`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            router.reload();
        },
    });
};

const submitGuestRegistration = (): void => {
    if (props.tournament === null) {
        return;
    }

    guestForm.post(`/api/torneos/${props.tournament.id}/inscripcion`, {
        preserveScroll: true,
        onSuccess: () => {
            showGuestModal.value = false;
            guestForm.reset();
            router.reload();
        },
    });
};

const closeGuestModal = (): void => {
    showGuestModal.value = false;
    guestForm.reset();
    guestForm.clearErrors();
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

const confirmWithdraw = (): void => {
    if (props.tournament === null) {
        return;
    }

    withdrawProcessing.value = true;
    router.delete(`/api/torneos/${props.tournament.id}/inscripcion`, {
        preserveScroll: true,
        onSuccess: () => {
            showWithdrawConfirm.value = false;
            withdrawProcessing.value = false;
            router.reload();
        },
        onError: () => {
            withdrawProcessing.value = false;
        },
    });
};

const handleWithdrawKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        closeWithdrawModal();
    }
};
</script>

<template>
    <div v-if="tournament" class="rounded-lg border border-purple-200 bg-purple-50 p-4 dark:border-purple-900 dark:bg-purple-950/30">
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
                <!-- Tournament Info Header -->
                <div class="mb-3 flex items-center gap-2">
                    <!-- Trophy icon -->
                    <svg
                        class="h-5 w-5 text-purple-600 dark:text-purple-400"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 21h8m-4-4v4m-4-8a4 4 0 01-4-4V5a2 2 0 012-2h8a2 2 0 012 2v4a4 4 0 01-4 4m-4 0h8"
                        />
                    </svg>
                    <h3 class="font-semibold text-purple-900 dark:text-purple-100">
                        {{ t('tournaments.public.event_tournament') }}
                    </h3>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="{
                            'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400': tournament.is_registration_open,
                            'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400': tournament.is_in_progress,
                            'bg-gray-100 text-gray-800 dark:bg-stone-700 dark:text-stone-300': tournament.is_finished || (!tournament.is_registration_open && !tournament.is_in_progress),
                        }"
                    >
                        {{ tournament.status_label }}
                    </span>
                </div>

                <!-- Tournament Stats -->
                <div class="mb-3 flex flex-wrap gap-4 text-sm text-purple-700 dark:text-purple-300">
                    <span class="flex items-center gap-1">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        {{ tournament.participant_count }}
                        <template v-if="tournament.max_participants">
                            / {{ tournament.max_participants }}
                        </template>
                        {{ t('tournaments.public.participants') }}
                    </span>

                    <span v-if="tournament.current_round > 0" class="flex items-center gap-1">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        {{ t('tournaments.public.round_label', { current: tournament.current_round, max: tournament.max_rounds || '?' }) }}
                    </span>
                </div>

                <!-- Registration Status -->
                <div v-if="isRegistered" class="mb-3">
                    <span class="inline-flex items-center gap-1 text-sm font-medium text-green-700 dark:text-green-400">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        {{ t('tournaments.public.already_registered') }}
                    </span>
                </div>

                <!-- Spots Available Info -->
                <div v-else-if="tournament.is_registration_open && spotsRemaining !== null" class="mb-3">
                    <span
                        class="text-sm"
                        :class="isFull ? 'text-red-600 dark:text-red-400' : 'text-purple-600 dark:text-purple-400'"
                    >
                        {{ isFull
                            ? t('tournaments.public.tournament_full')
                            : t('tournaments.public.spots_remaining', { count: spotsRemaining })
                        }}
                    </span>
                </div>

                <!-- Link to Tournament Page -->
                <Link
                    :href="`/torneos/${tournament.slug}`"
                    class="text-sm text-purple-600 hover:text-purple-800 hover:underline dark:text-purple-400 dark:hover:text-purple-300"
                >
                    {{ t('tournaments.public.view_tournament') }}
                    <span aria-hidden="true">&rarr;</span>
                </Link>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-shrink-0 flex-col gap-2">
                <!-- Register Button -->
                <BaseButton
                    v-if="!isRegistered && canRegister && tournament.is_registration_open && !isFull"
                    variant="primary"
                    size="sm"
                    @click="handleRegister"
                >
                    {{ t('tournaments.public.register') }}
                </BaseButton>

                <!-- Withdraw Button -->
                <BaseButton
                    v-if="canWithdraw"
                    variant="danger"
                    size="sm"
                    @click="handleWithdraw"
                >
                    {{ t('tournaments.public.withdraw') }}
                </BaseButton>
            </div>
        </div>
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
                                class="block text-sm font-medium text-base-secondary mb-1"
                            >
                                {{ t('tournaments.public.guest_name') }}
                                <span class="text-red-500 dark:text-red-400" aria-label="required">*</span>
                            </label>
                            <input
                                id="guest-name"
                                ref="guestNameInput"
                                v-model="guestForm.guest_name"
                                type="text"
                                required
                                :disabled="guestForm.processing"
                                :aria-invalid="!!guestForm.errors.guest_name"
                                :aria-describedby="guestForm.errors.guest_name ? 'guest-name-error' : undefined"
                                class="w-full px-4 py-2 border border-default rounded-md shadow-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 disabled:bg-stone-100 disabled:cursor-not-allowed transition-colors dark:bg-stone-700 dark:text-stone-100 dark:placeholder-stone-400 dark:focus:ring-primary-400 dark:focus:border-primary-400 dark:disabled:bg-stone-800 dark:disabled:text-stone-500"
                                :class="{
                                    'border-red-500 focus:ring-red-500 focus:border-red-500 dark:border-red-400':
                                        guestForm.errors.guest_name,
                                }"
                            />
                            <p
                                v-if="guestForm.errors.guest_name"
                                id="guest-name-error"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                                role="alert"
                            >
                                {{ guestForm.errors.guest_name }}
                            </p>
                        </div>

                        <!-- Email field -->
                        <div>
                            <label
                                for="guest-email"
                                class="block text-sm font-medium text-base-secondary mb-1"
                            >
                                {{ t('tournaments.public.guest_email') }}
                                <span class="text-red-500 dark:text-red-400" aria-label="required">*</span>
                            </label>
                            <input
                                id="guest-email"
                                v-model="guestForm.guest_email"
                                type="email"
                                required
                                :disabled="guestForm.processing"
                                :aria-invalid="!!guestForm.errors.guest_email"
                                :aria-describedby="guestForm.errors.guest_email ? 'guest-email-error' : undefined"
                                class="w-full px-4 py-2 border border-default rounded-md shadow-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500 disabled:bg-stone-100 disabled:cursor-not-allowed transition-colors dark:bg-stone-700 dark:text-stone-100 dark:placeholder-stone-400 dark:focus:ring-primary-400 dark:focus:border-primary-400 dark:disabled:bg-stone-800 dark:disabled:text-stone-500"
                                :class="{
                                    'border-red-500 focus:ring-red-500 focus:border-red-500 dark:border-red-400':
                                        guestForm.errors.guest_email,
                                }"
                            />
                            <p
                                v-if="guestForm.errors.guest_email"
                                id="guest-email-error"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                                role="alert"
                            >
                                {{ guestForm.errors.guest_email }}
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
                                    :disabled="guestForm.processing"
                                    :aria-invalid="!!guestForm.errors.gdpr_consent"
                                    :aria-describedby="guestForm.errors.gdpr_consent ? 'event-guest-gdpr-error' : undefined"
                                    class="mt-1 h-4 w-4 rounded border-default text-primary focus:ring-primary-500 disabled:cursor-not-allowed dark:bg-stone-700"
                                />
                                <label
                                    for="event-guest-gdpr"
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
                                    <span class="text-red-500 dark:text-red-400" aria-label="required">*</span>
                                </label>
                            </div>
                            <p
                                v-if="guestForm.errors.gdpr_consent"
                                id="event-guest-gdpr-error"
                                class="mt-1 text-sm text-red-600 dark:text-red-400"
                                role="alert"
                            >
                                {{ guestForm.errors.gdpr_consent }}
                            </p>
                        </div>

                        <!-- Actions -->
                        <div class="mt-6 flex justify-end gap-3">
                            <BaseButton
                                type="button"
                                variant="secondary"
                                :disabled="guestForm.processing"
                                @click="closeGuestModal"
                            >
                                {{ t('common.cancel') }}
                            </BaseButton>
                            <BaseButton
                                type="submit"
                                variant="primary"
                                :loading="guestForm.processing"
                                :disabled="guestForm.processing"
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
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                        <svg
                            class="h-6 w-6 text-red-600 dark:text-red-400"
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
</template>
