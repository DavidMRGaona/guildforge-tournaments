import { describe, expect, it } from 'vitest';

/**
 * The core defines its colour tokens as plain classes (`border-default`, `text-base-muted`…), not
 * as Tailwind theme colours. A utility such as `divide-default` therefore generates nothing: the
 * dividers fall back to `currentColor` and show almost black (white in dark mode). Lists use
 * `divide-[var(--color-border)]`, as the core does.
 *
 * `border-default` and `border-strong` are real core classes, so they are not flagged here.
 */
const SOURCES = import.meta.glob<string>('../../../resources/js/**/*.{vue,ts}', {
    query: '?raw',
    import: 'default',
    eager: true,
});

/** Colour utilities that read a theme colour the core does not have. */
const UNDEFINED_UTILITY =
    /\b(?:divide|ring|outline|decoration|fill|stroke|accent|caret|placeholder|from|via|to|bg|text)-(?:border-)?(?:default|strong)\b/;

describe('semantic colour utilities', () => {
    it('reads the module sources', () => {
        expect(Object.keys(SOURCES).length).toBeGreaterThan(10);
    });

    it('never uses a colour utility the core does not define', () => {
        const offenders = Object.entries(SOURCES)
            .filter(([, source]) => UNDEFINED_UTILITY.test(source))
            .map(([path]) => path);

        expect(offenders).toEqual([]);
    });

    it('spots the utilities that fell back to currentColor', () => {
        for (const bad of [
            'divide-default',
            'divide-border-default',
            'ring-strong',
            'text-default',
        ]) {
            expect(UNDEFINED_UTILITY.test(`class="${bad}"`)).toBe(true);
        }

        for (const good of ['border-default', 'border-strong', 'divide-[var(--color-border)]']) {
            expect(UNDEFINED_UTILITY.test(`class="${good}"`)).toBe(false);
        }
    });
});
