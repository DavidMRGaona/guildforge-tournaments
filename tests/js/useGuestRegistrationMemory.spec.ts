import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { computed } from 'vue';

type Composable = typeof import('../../resources/js/composables/useGuestRegistrationMemory');
type Contract = typeof import('@/contracts/cookieConsent');
type Memory = ReturnType<Composable['useGuestRegistrationMemory']>;

const NOW = new Date('2026-10-24T10:00:00Z');
const DAY = 24 * 60 * 60 * 1000;

// The core's consent contract keeps its provider in module state: every test loads fresh copies
let composable: Composable;
let contract: Contract;

function withConsent(preferences: boolean): void {
    contract.registerCookieConsentProvider({
        hasConsent: (category: string) => category === 'preferences' && preferences,
    });
}

function useMemory(): Memory {
    return composable.useGuestRegistrationMemory();
}

function storedCookie(): Record<string, number> {
    const value = document.cookie
        .split('; ')
        .find((part) => part.startsWith('tn_guest_registrations='))
        ?.split('=')[1];
    // A cookie removed by an earlier test can linger with an empty value
    return value === undefined || value === '' ? {} : JSON.parse(decodeURIComponent(value));
}

describe('useGuestRegistrationMemory', () => {
    beforeEach(async () => {
        vi.resetModules();
        composable = await import('../../resources/js/composables/useGuestRegistrationMemory');
        contract = await import('@/contracts/cookieConsent');
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('remembers a registration until the registration closes', () => {
        withConsent(true);
        const memory = useMemory();

        memory.remember('tournament-1', new Date(NOW.getTime() + 2 * DAY).toISOString());

        expect(memory.wasRegisteredHere('tournament-1')).toBe(true);
        expect(memory.wasRegisteredHere('tournament-2')).toBe(false);

        vi.setSystemTime(NOW.getTime() + 2 * DAY + 1);
        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);
    });

    it('stores nothing without consent to the preferences cookies', () => {
        withConsent(false);
        const memory = useMemory();

        memory.remember('tournament-1', null);

        expect(storedCookie()).toEqual({});
        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);
    });

    it('stores nothing without a cookie consent module on the page', () => {
        const memory = useMemory();

        memory.remember('tournament-1', null);

        expect(storedCookie()).toEqual({});
    });

    it('keeps tournament ids only, for 90 days at most', () => {
        withConsent(true);
        const memory = useMemory();

        memory.remember('tournament-1', null);
        memory.remember('tournament-2', new Date(NOW.getTime() + 400 * DAY).toISOString());

        expect(storedCookie()).toEqual({
            'tournament-1': NOW.getTime() + 90 * DAY,
            'tournament-2': NOW.getTime() + 90 * DAY,
        });
    });

    it('drops the entries that no longer matter when it writes', () => {
        withConsent(true);
        const memory = useMemory();
        memory.remember('tournament-1', new Date(NOW.getTime() + DAY).toISOString());

        vi.setSystemTime(NOW.getTime() + 2 * DAY);
        memory.remember('tournament-2', null);

        expect(Object.keys(storedCookie())).toEqual(['tournament-2']);
    });

    it('ignores a cookie it cannot read', () => {
        withConsent(true);
        document.cookie = 'tn_guest_registrations=not-json; Path=/';
        const memory = useMemory();

        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);
    });

    it('follows a consent given after the page loaded', () => {
        withConsent(true);
        useMemory().remember('tournament-1', null);

        withConsent(false);
        const memory = useMemory();
        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);

        withConsent(true);
        expect(memory.wasRegisteredHere('tournament-1')).toBe(true);
    });

    it('updates a computed answer when consent changes and after writing', () => {
        const memory = useMemory();
        const registered = computed(() => memory.wasRegisteredHere('tournament-3'));
        expect(registered.value).toBe(false);

        withConsent(true);
        expect(registered.value).toBe(false);

        memory.remember('tournament-3', null);
        expect(registered.value).toBe(true);

        withConsent(false);
        expect(registered.value).toBe(false);
    });
});
