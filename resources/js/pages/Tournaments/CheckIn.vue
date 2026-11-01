<script setup lang="ts">
import { Ban, ChevronLeft, CircleCheck, Clock, X } from '@lucide/vue';
import { computed, ref, reactive, useId } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type {
    TournamentCheckInProps,
    CheckInJsonResponse,
    CheckInField,
} from '../../types/tournaments';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useAuth } from '@/composables/useAuth';
import { useVerificationGate } from '@/composables/useVerificationGate';
import { useNotifications } from '@/composables/useNotifications';
import { useRoutes } from '@/composables/useRoutes';
import { VENUE_TIMEZONE } from '@/utils/datetime';
import { csrfHeaders, isCsrfFailure } from '@/utils/csrf';

type FormErrors = Partial<Record<CheckInField, string | undefined>>;

const props = defineProps<TournamentCheckInProps>();

const { t, locale } = useI18n();
const routes = useRoutes();
const { isAuthenticated } = useAuth();
const { error: notifyError, success: notifySuccess } = useNotifications();
// A signed-in user checks in as themselves, which is closed to accounts that must verify their email
const gate = useVerificationGate();
const gateReasonId = useId();

// Form state
const emailForm = reactive({
    email: '',
    gdpr_consent: false,
});
const formErrors = reactive<FormErrors>({});
const isProcessing = ref(false);

// Computed states
const isWindowOpen = computed(() => props.checkInWindow.status === 'open');
const isWindowNotYet = computed(() => props.checkInWindow.status === 'not_yet');
const isWindowClosed = computed(() => props.checkInWindow.status === 'closed');
const isNotAvailable = computed(() => props.checkInWindow.status === 'not_available');

const isAlreadyCheckedIn = computed(() => {
    return props.userRegistration?.status === 'checked_in';
});

const isRegistered = computed(() => {
    return props.userRegistration !== null;
});

const checkedInTime = computed(() => {
    if (!props.userRegistration?.checked_in_at) return null;
    const date = new Date(props.userRegistration.checked_in_at);
    return date.toLocaleTimeString(locale.value, {
        timeZone: VENUE_TIMEZONE,
        hour: '2-digit',
        minute: '2-digit',
    });
});

const formatDateTime = (dateString: string | null): string => {
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

// The endpoint answers every outcome with JSON; anything else (e.g. an error page) has no message
const readBody = async (
    response: Awaited<ReturnType<typeof fetch>>
): Promise<CheckInJsonResponse> => {
    try {
        return (await response.json()) as CheckInJsonResponse;
    } catch {
        return {};
    }
};

// Handle check-in for authenticated user
const handleAuthenticatedCheckIn = async (): Promise<void> => {
    if (gate.blocked.value) {
        return;
    }

    isProcessing.value = true;

    try {
        const response = await fetch(`/torneos/${props.tournament.slug}/check-in`, {
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

        const data = await readBody(response);

        if (response.ok) {
            notifySuccess(data.message || t('tournaments.check_in.success'));
            router.reload();
        } else {
            notifyError(data.message || t('tournaments.check_in.connection_error'));
        }
    } catch {
        notifyError(t('tournaments.check_in.connection_error'));
    } finally {
        isProcessing.value = false;
    }
};

// Handle check-in with email form
const handleEmailCheckIn = async (): Promise<void> => {
    isProcessing.value = true;
    delete formErrors.email;
    delete formErrors.gdpr_consent;

    try {
        const response = await fetch(`/torneos/${props.tournament.slug}/check-in`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...csrfHeaders(),
            },
            body: JSON.stringify({
                email: emailForm.email,
                gdpr_consent: emailForm.gdpr_consent,
            }),
        });

        if (isCsrfFailure(response)) {
            notifyError(t('tournaments.public.session_expired'));
            return;
        }

        // Signed in meanwhile (e.g. in another tab) with an account that must verify
        if (gate.isVerificationRequired(response)) {
            gate.goToNotice();
            return;
        }

        const data = await readBody(response);

        if (response.ok) {
            notifySuccess(data.message || t('tournaments.check_in.success'));
            router.reload();
        } else if (response.status === 422 && (data.errors?.email || data.errors?.gdpr_consent)) {
            // Shown under the field (invalid or unknown email, unconfirmed registration, consent)
            formErrors.email = data.errors.email?.[0];
            formErrors.gdpr_consent = data.errors.gdpr_consent?.[0];
        } else {
            notifyError(data.message || t('tournaments.check_in.connection_error'));
        }
    } catch {
        notifyError(t('tournaments.check_in.connection_error'));
    } finally {
        isProcessing.value = false;
    }
};
</script>

<template>
    <div class="mx-auto max-w-lg px-4 py-8 sm:px-6 lg:px-8">
        <!-- Back link -->
        <div class="mb-6">
            <Link
                :href="`/torneos/${tournament.slug}`"
                class="inline-flex items-center text-sm text-[color:var(--color-text-secondary)] hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 focus:ring-offset-surface"
            >
                <ChevronLeft class="mr-1 h-4 w-4" aria-hidden="true" />
                {{ t('common.back') }}
            </Link>
        </div>

        <div class="overflow-hidden rounded-lg bg-surface shadow">
            <div class="p-6 sm:p-8">
                <!-- Header -->
                <div class="mb-6 text-center">
                    <h1 class="text-2xl font-bold text-base-primary">
                        {{ t('tournaments.check_in.title') }}
                    </h1>
                    <p class="mt-2 text-base-muted">
                        {{ tournament.name }}
                    </p>
                </div>

                <!-- Not Available State -->
                <div v-if="isNotAvailable" class="rounded-lg bg-page p-6 text-center">
                    <Ban class="mx-auto h-12 w-12 text-base-muted" aria-hidden="true" />
                    <p class="mt-4 text-base-muted">
                        {{ t('tournaments.check_in.not_allowed') }}
                    </p>
                </div>

                <!-- Window Not Yet Open -->
                <div v-else-if="isWindowNotYet" class="rounded-lg bg-primary-light p-6 text-center">
                    <Clock class="mx-auto h-12 w-12 text-primary" aria-hidden="true" />
                    <p class="mt-4 font-medium text-base-primary">
                        {{
                            t('tournaments.check_in.window_opens_at', {
                                date: formatDateTime(checkInWindow.opens_at)?.split(',')[0] || '',
                                time:
                                    formatDateTime(checkInWindow.opens_at)?.split(',')[1]?.trim() ||
                                    '',
                            })
                        }}
                    </p>
                </div>

                <!-- Window Closed -->
                <div v-else-if="isWindowClosed" class="rounded-lg bg-error-light p-6 text-center">
                    <X class="mx-auto h-12 w-12 text-error" aria-hidden="true" />
                    <p class="mt-4 font-medium text-base-primary">
                        {{ t('tournaments.check_in.window_closed_started') }}
                    </p>
                </div>

                <!-- Window Open -->
                <div v-else-if="isWindowOpen">
                    <!-- Already Checked In -->
                    <div v-if="isAlreadyCheckedIn" class="rounded-lg bg-success/10 p-6 text-center">
                        <CircleCheck class="mx-auto h-12 w-12 text-success" aria-hidden="true" />
                        <p class="mt-4 font-medium text-success">
                            {{ t('tournaments.check_in.success') }}
                        </p>
                        <p v-if="checkedInTime" class="mt-2 text-sm text-success">
                            {{ t('tournaments.check_in.success_time', { time: checkedInTime }) }}
                        </p>
                    </div>

                    <!-- Authenticated User - Button to check in -->
                    <div v-else-if="isAuthenticated && isRegistered">
                        <p class="mb-4 text-center text-base-muted">
                            {{ t('tournaments.check_in.enter_email') }}
                        </p>
                        <BaseButton
                            variant="primary"
                            class="w-full"
                            :loading="isProcessing"
                            :disabled="isProcessing || gate.blocked.value"
                            :aria-describedby="gate.blocked.value ? gateReasonId : undefined"
                            @click="handleAuthenticatedCheckIn"
                        >
                            {{ t('tournaments.check_in.submit') }}
                        </BaseButton>
                        <p
                            v-if="gate.blocked.value"
                            :id="gateReasonId"
                            class="mt-3 rounded-md bg-warning-light p-3 text-sm text-base-primary"
                        >
                            {{ gate.reason('checkIn') }}
                            <Link
                                :href="gate.noticeUrl"
                                class="font-semibold underline underline-offset-2 hover:no-underline"
                            >
                                {{ gate.linkLabel.value }}
                                <span aria-hidden="true">→</span>
                            </Link>
                        </p>
                    </div>

                    <!-- Authenticated but not registered -->
                    <div
                        v-else-if="isAuthenticated && !isRegistered"
                        class="rounded-lg bg-primary-light p-6 text-center"
                    >
                        <p class="text-base-primary">
                            {{ t('tournaments.check_in.not_registered') }}
                        </p>
                        <Link
                            :href="`/torneos/${tournament.slug}`"
                            class="mt-4 inline-block text-primary hover:underline"
                        >
                            {{ t('tournaments.public.register') }}
                        </Link>
                    </div>

                    <!-- Guest / Not authenticated - Email form -->
                    <div v-else>
                        <p class="mb-4 text-center text-base-muted">
                            {{ t('tournaments.check_in.enter_email') }}
                        </p>

                        <form class="space-y-4" @submit.prevent="handleEmailCheckIn">
                            <div>
                                <label
                                    for="check-in-email"
                                    class="mb-1 block text-sm font-medium text-base-secondary"
                                >
                                    {{ t('common.email') }}
                                    <span class="text-error" aria-label="required">*</span>
                                </label>
                                <input
                                    id="check-in-email"
                                    v-model="emailForm.email"
                                    type="email"
                                    required
                                    :disabled="isProcessing"
                                    :aria-invalid="!!formErrors.email"
                                    :aria-describedby="formErrors.email ? 'email-error' : undefined"
                                    class="w-full rounded-md border border-default px-4 py-2 shadow-sm transition-colors focus:border-primary-500 focus:ring-2 focus:ring-primary-500 disabled:cursor-not-allowed disabled:bg-base-secondary/10 bg-surface text-base-primary placeholder-base-muted"
                                    :class="{
                                        'border-error focus:border-error focus:ring-error':
                                            formErrors.email,
                                    }"
                                />
                                <p
                                    v-if="formErrors.email"
                                    id="email-error"
                                    class="mt-1 text-sm text-error"
                                    role="alert"
                                >
                                    {{ formErrors.email }}
                                </p>
                            </div>

                            <!-- GDPR Consent -->
                            <div>
                                <div class="flex items-start gap-2">
                                    <input
                                        id="checkin-gdpr"
                                        v-model="emailForm.gdpr_consent"
                                        type="checkbox"
                                        required
                                        :disabled="isProcessing"
                                        :aria-invalid="!!formErrors.gdpr_consent"
                                        :aria-describedby="
                                            formErrors.gdpr_consent
                                                ? 'checkin-gdpr-error'
                                                : undefined
                                        "
                                        class="mt-1 h-4 w-4 rounded border-default text-primary focus:ring-primary-500 disabled:cursor-not-allowed bg-surface"
                                    />
                                    <label for="checkin-gdpr" class="text-sm text-base-muted">
                                        {{ t('tournaments.check_in.gdpr_consent') }}
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
                                    v-if="formErrors.gdpr_consent"
                                    id="checkin-gdpr-error"
                                    class="mt-1 text-sm text-error"
                                    role="alert"
                                >
                                    {{ formErrors.gdpr_consent }}
                                </p>
                            </div>

                            <BaseButton
                                type="submit"
                                variant="primary"
                                class="w-full"
                                :loading="isProcessing"
                                :disabled="isProcessing"
                            >
                                {{ t('tournaments.check_in.submit') }}
                            </BaseButton>
                        </form>

                        <!-- Login link -->
                        <div class="mt-4 text-center">
                            <span class="text-sm text-base-muted">
                                {{ t('tournaments.check_in.or_login') }}:
                            </span>
                            <Link
                                href="/iniciar-sesion"
                                class="ml-1 text-sm font-medium text-primary hover:underline"
                            >
                                {{ t('common.login') }}
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
