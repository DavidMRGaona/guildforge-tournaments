<script setup lang="ts">
import { computed, ref, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { TournamentCheckInProps } from '../../types/tournaments';
import DefaultLayout from '@/layouts/DefaultLayout.vue';
import BaseButton from '@/components/ui/BaseButton.vue';
import { useSeo } from '@/composables/useSeo';
import { useAuth } from '@/composables/useAuth';
import { useNotifications } from '@/composables/useNotifications';

interface FormErrors {
    email?: string;
    gdpr_consent?: string;
}

const props = defineProps<TournamentCheckInProps>();

const { t, locale } = useI18n();
const { isAuthenticated } = useAuth();
const { error: notifyError, success: notifySuccess } = useNotifications();

useSeo({
    title: `${t('tournaments.check_in.title')} - ${props.tournament.name}`,
    description: t('tournaments.check_in.title'),
    canonical: `/torneos/${props.tournament.slug}/check-in`,
});

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
        hour: '2-digit',
        minute: '2-digit',
    });
});

const formatDateTime = (dateString: string | null): string => {
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

const getCsrfToken = (): string => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
};

// Handle check-in for authenticated user
const handleAuthenticatedCheckIn = async (): Promise<void> => {
    isProcessing.value = true;

    try {
        const response = await fetch(`/torneos/${props.tournament.slug}/check-in`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
        });

        if (response.ok) {
            notifySuccess(t('tournaments.check_in.success'));
            router.reload();
        } else {
            const data = await response.json();
            notifyError(data.message || t('tournaments.check_in.window_closed'));
        }
    } catch {
        notifyError(t('tournaments.check_in.window_closed'));
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
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                email: emailForm.email,
                gdpr_consent: emailForm.gdpr_consent,
            }),
        });

        const data = await response.json();

        if (response.ok) {
            notifySuccess(t('tournaments.check_in.success'));
            router.reload();
        } else if (response.status === 422 && data.errors) {
            formErrors.email = data.errors.email?.[0];
            formErrors.gdpr_consent = data.errors.gdpr_consent?.[0];
        } else {
            notifyError(data.message || t('tournaments.check_in.not_found'));
        }
    } catch {
        notifyError(t('tournaments.check_in.window_closed'));
    } finally {
        isProcessing.value = false;
    }
};
</script>

<template>
    <DefaultLayout>
        <div class="mx-auto max-w-lg px-4 py-8 sm:px-6 lg:px-8">
            <!-- Back link -->
            <div class="mb-6">
                <Link
                    :href="`/torneos/${tournament.slug}`"
                    class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 dark:text-stone-400 dark:hover:text-stone-300 dark:focus:ring-offset-stone-900"
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

            <div class="overflow-hidden rounded-lg bg-white shadow dark:bg-stone-800 dark:shadow-stone-900/50">
                <div class="p-6 sm:p-8">
                    <!-- Header -->
                    <div class="mb-6 text-center">
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-stone-100">
                            {{ t('tournaments.check_in.title') }}
                        </h1>
                        <p class="mt-2 text-gray-600 dark:text-stone-400">
                            {{ tournament.name }}
                        </p>
                    </div>

                    <!-- Not Available State -->
                    <div
                        v-if="isNotAvailable"
                        class="rounded-lg bg-gray-50 p-6 text-center dark:bg-stone-900/30"
                    >
                        <svg
                            class="mx-auto h-12 w-12 text-gray-400 dark:text-stone-500"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"
                            />
                        </svg>
                        <p class="mt-4 text-gray-600 dark:text-stone-400">
                            {{ t('tournaments.check_in.not_allowed') }}
                        </p>
                    </div>

                    <!-- Window Not Yet Open -->
                    <div
                        v-else-if="isWindowNotYet"
                        class="rounded-lg bg-amber-50 p-6 text-center dark:bg-amber-900/20"
                    >
                        <svg
                            class="mx-auto h-12 w-12 text-amber-500 dark:text-amber-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                            />
                        </svg>
                        <p class="mt-4 font-medium text-amber-800 dark:text-amber-300">
                            {{ t('tournaments.check_in.window_opens_at', {
                                date: formatDateTime(checkInWindow.opens_at)?.split(',')[0] || '',
                                time: formatDateTime(checkInWindow.opens_at)?.split(',')[1]?.trim() || ''
                            }) }}
                        </p>
                    </div>

                    <!-- Window Closed -->
                    <div
                        v-else-if="isWindowClosed"
                        class="rounded-lg bg-red-50 p-6 text-center dark:bg-red-900/20"
                    >
                        <svg
                            class="mx-auto h-12 w-12 text-red-500 dark:text-red-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                        <p class="mt-4 font-medium text-red-800 dark:text-red-300">
                            {{ t('tournaments.check_in.window_closed_started') }}
                        </p>
                    </div>

                    <!-- Window Open -->
                    <div v-else-if="isWindowOpen">
                        <!-- Already Checked In -->
                        <div
                            v-if="isAlreadyCheckedIn"
                            class="rounded-lg bg-green-50 p-6 text-center dark:bg-green-900/20"
                        >
                            <svg
                                class="mx-auto h-12 w-12 text-green-500 dark:text-green-400"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                            <p class="mt-4 font-medium text-green-800 dark:text-green-300">
                                {{ t('tournaments.check_in.success') }}
                            </p>
                            <p v-if="checkedInTime" class="mt-2 text-sm text-green-600 dark:text-green-400">
                                {{ t('tournaments.check_in.success_time', { time: checkedInTime }) }}
                            </p>
                        </div>

                        <!-- Authenticated User - Button to check in -->
                        <div v-else-if="isAuthenticated && isRegistered">
                            <p class="mb-4 text-center text-gray-600 dark:text-stone-400">
                                {{ t('tournaments.check_in.enter_email') }}
                            </p>
                            <BaseButton
                                variant="primary"
                                class="w-full"
                                :loading="isProcessing"
                                :disabled="isProcessing"
                                @click="handleAuthenticatedCheckIn"
                            >
                                {{ t('tournaments.check_in.submit') }}
                            </BaseButton>
                        </div>

                        <!-- Authenticated but not registered -->
                        <div
                            v-else-if="isAuthenticated && !isRegistered"
                            class="rounded-lg bg-amber-50 p-6 text-center dark:bg-amber-900/20"
                        >
                            <p class="text-amber-800 dark:text-amber-300">
                                {{ t('tournaments.check_in.not_registered') }}
                            </p>
                            <Link
                                :href="`/torneos/${tournament.slug}`"
                                class="mt-4 inline-block text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300"
                            >
                                {{ t('tournaments.public.register') }}
                            </Link>
                        </div>

                        <!-- Guest / Not authenticated - Email form -->
                        <div v-else>
                            <p class="mb-4 text-center text-gray-600 dark:text-stone-400">
                                {{ t('tournaments.check_in.enter_email') }}
                            </p>

                            <form @submit.prevent="handleEmailCheckIn" class="space-y-4">
                                <div>
                                    <label
                                        for="check-in-email"
                                        class="mb-1 block text-sm font-medium text-stone-700 dark:text-stone-300"
                                    >
                                        {{ t('common.email') }}
                                        <span class="text-red-500 dark:text-red-400" aria-label="required">*</span>
                                    </label>
                                    <input
                                        id="check-in-email"
                                        v-model="emailForm.email"
                                        type="email"
                                        required
                                        :disabled="isProcessing"
                                        :aria-invalid="!!formErrors.email"
                                        :aria-describedby="formErrors.email ? 'email-error' : undefined"
                                        class="w-full rounded-md border border-stone-300 px-4 py-2 shadow-sm transition-colors focus:border-amber-500 focus:ring-2 focus:ring-amber-500 disabled:cursor-not-allowed disabled:bg-stone-100 dark:border-stone-600 dark:bg-stone-700 dark:text-stone-100 dark:placeholder-stone-400 dark:focus:border-amber-400 dark:focus:ring-amber-400 dark:disabled:bg-stone-800 dark:disabled:text-stone-500"
                                        :class="{
                                            'border-red-500 focus:border-red-500 focus:ring-red-500 dark:border-red-400':
                                                formErrors.email,
                                        }"
                                    />
                                    <p
                                        v-if="formErrors.email"
                                        id="email-error"
                                        class="mt-1 text-sm text-red-600 dark:text-red-400"
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
                                            :aria-describedby="formErrors.gdpr_consent ? 'checkin-gdpr-error' : undefined"
                                            class="mt-1 h-4 w-4 rounded border-stone-300 text-amber-600 focus:ring-amber-500 disabled:cursor-not-allowed dark:border-stone-600 dark:bg-stone-700"
                                        />
                                        <label
                                            for="checkin-gdpr"
                                            class="text-sm text-stone-600 dark:text-stone-400"
                                        >
                                            {{ t('tournaments.check_in.gdpr_consent') }}
                                            <a
                                                href="/politica-de-privacidad"
                                                target="_blank"
                                                class="text-amber-600 underline hover:text-amber-700 dark:text-amber-500 dark:hover:text-amber-400"
                                            >
                                                {{ t('tournaments.public.privacy_policy') }}
                                            </a>
                                            <span class="text-red-500 dark:text-red-400" aria-label="required">*</span>
                                        </label>
                                    </div>
                                    <p
                                        v-if="formErrors.gdpr_consent"
                                        id="checkin-gdpr-error"
                                        class="mt-1 text-sm text-red-600 dark:text-red-400"
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
                                <span class="text-sm text-gray-500 dark:text-stone-400">
                                    {{ t('tournaments.check_in.or_login') }}:
                                </span>
                                <Link
                                    href="/iniciar-sesion"
                                    class="ml-1 text-sm font-medium text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300"
                                >
                                    {{ t('common.login') }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </DefaultLayout>
</template>
