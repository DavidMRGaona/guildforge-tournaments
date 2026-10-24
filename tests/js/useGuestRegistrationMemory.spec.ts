import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';
import { useGuestRegistrationMemory } from '../../resources/js/composables/useGuestRegistrationMemory';

type Memory = ReturnType<typeof useGuestRegistrationMemory>;

const NOW = new Date('2026-10-24T10:00:00Z');
const DAY = 24 * 60 * 60 * 1000;

function withConsent(preferences: boolean): void {
    globalThis.GuildForgeCookieConsent = {
        hasConsent: (category: string) => category === 'preferences' && preferences,
    };
}

// The composable hooks into mount and unmount: run it inside a component
function mountMemory(): Memory {
    let memory: Memory | undefined;
    mount(defineComponent({
        setup() {
            memory = useGuestRegistrationMemory();
            return () => h('div');
        },
    }));

    if (memory === undefined) {
        throw new Error('The composable did not run');
    }
    return memory;
}

function storedCookie(): Record<string, number> {
    const value = document.cookie.split('; ').find((part) => part.startsWith('tn_guest_registrations='))?.split('=')[1];
    // A cookie removed by an earlier test can linger with an empty value
    return value === undefined || value === '' ? {} : JSON.parse(decodeURIComponent(value));
}

describe('useGuestRegistrationMemory', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
        globalThis.GuildForgeCookieConsent = undefined;
    });

    it('remembers a registration until the registration closes', () => {
        withConsent(true);
        const memory = mountMemory();

        memory.remember('tournament-1', new Date(NOW.getTime() + 2 * DAY).toISOString());

        expect(memory.wasRegisteredHere('tournament-1')).toBe(true);
        expect(memory.wasRegisteredHere('tournament-2')).toBe(false);

        vi.setSystemTime(NOW.getTime() + 2 * DAY + 1);
        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);
    });

    it('stores nothing without consent to the preferences cookies', () => {
        withConsent(false);
        const memory = mountMemory();

        memory.remember('tournament-1', null);

        expect(storedCookie()).toEqual({});
        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);
    });

    it('stores nothing without the cookie banner on the page', () => {
        const memory = mountMemory();

        memory.remember('tournament-1', null);

        expect(storedCookie()).toEqual({});
    });

    it('keeps tournament ids only, for 90 days at most', () => {
        withConsent(true);
        const memory = mountMemory();

        memory.remember('tournament-1', null);
        memory.remember('tournament-2', new Date(NOW.getTime() + 400 * DAY).toISOString());

        expect(storedCookie()).toEqual({
            'tournament-1': NOW.getTime() + 90 * DAY,
            'tournament-2': NOW.getTime() + 90 * DAY,
        });
    });

    it('drops the entries that no longer matter when it writes', () => {
        withConsent(true);
        const memory = mountMemory();
        memory.remember('tournament-1', new Date(NOW.getTime() + DAY).toISOString());

        vi.setSystemTime(NOW.getTime() + 2 * DAY);
        memory.remember('tournament-2', null);

        expect(Object.keys(storedCookie())).toEqual(['tournament-2']);
    });

    it('ignores a cookie it cannot read', () => {
        withConsent(true);
        document.cookie = 'tn_guest_registrations=not-json; Path=/';
        const memory = mountMemory();

        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);
    });

    it('follows a consent given after the page loaded', async () => {
        withConsent(true);
        const writer = mountMemory();
        writer.remember('tournament-1', null);

        withConsent(false);
        const memory = mountMemory();
        expect(memory.wasRegisteredHere('tournament-1')).toBe(false);

        withConsent(true);
        window.dispatchEvent(new Event('guildforge:cookie-consent-changed'));
        expect(memory.wasRegisteredHere('tournament-1')).toBe(true);
    });
});
