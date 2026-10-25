import { ref } from 'vue';
import { hasCookieConsent } from '@/contracts/cookieConsent';

const COOKIE_NAME = 'tn_guest_registrations';
const MAX_AGE_SECONDS = 90 * 24 * 60 * 60;

type Remembered = Record<string, number>;

// Reactive: answers follow the banner registering later and the visitor changing their mind
const hasPreferencesConsent = (): boolean => hasCookieConsent('preferences');

// Tournament ids only (no emails or names), each with the moment the entry stops mattering
const readCookie = (): Remembered => {
    if (typeof document === 'undefined') {
        return {};
    }

    const entry = document.cookie.split('; ').find((part) => part.startsWith(`${COOKIE_NAME}=`));
    if (entry === undefined) {
        return {};
    }

    try {
        const parsed: unknown = JSON.parse(decodeURIComponent(entry.slice(COOKIE_NAME.length + 1)));
        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
            return {};
        }

        const remembered: Remembered = {};
        for (const [id, until] of Object.entries(parsed)) {
            if (typeof until === 'number') {
                remembered[id] = until;
            }
        }
        return remembered;
    } catch {
        return {};
    }
};

/**
 * Remembers, in this browser, the tournaments the visitor registered for as a guest, so the form
 * can warn before a second attempt. Reads and writes only with consent to the `preferences`
 * cookies; without a cookie consent module on the page nothing is stored.
 */
export function useGuestRegistrationMemory(): {
    wasRegisteredHere: (tournamentId: string) => boolean;
    remember: (tournamentId: string, until: string | null) => void;
} {
    // Vue cannot watch document.cookie: bumped after this browser writes it
    const writes = ref(0);

    const wasRegisteredHere = (tournamentId: string): boolean => {
        // Reading the counter makes the answer follow this browser's own writes
        void writes.value;

        if (!hasPreferencesConsent()) {
            return false;
        }

        const until = readCookie()[tournamentId];
        return until !== undefined && until > Date.now();
    };

    const remember = (tournamentId: string, until: string | null): void => {
        if (!hasPreferencesConsent()) {
            return;
        }

        const now = Date.now();
        const cap = now + MAX_AGE_SECONDS * 1000;
        const parsedUntil = until === null ? Number.NaN : new Date(until).getTime();
        const expiry = Number.isNaN(parsedUntil) ? cap : Math.min(Math.max(parsedUntil, now), cap);

        const remembered: Remembered = {};
        for (const [id, value] of Object.entries(readCookie())) {
            if (value > now) {
                remembered[id] = value;
            }
        }
        remembered[tournamentId] = expiry;

        document.cookie = `${COOKIE_NAME}=${encodeURIComponent(JSON.stringify(remembered))}; Max-Age=${MAX_AGE_SECONDS}; Path=/; SameSite=Lax${window.location.protocol === 'https:' ? '; Secure' : ''}`;
        writes.value += 1;
    };

    return { wasRegisteredHere, remember };
}
