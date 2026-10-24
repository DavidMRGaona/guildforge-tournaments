import { describe, expect, it } from 'vitest';
import { pickColor, statusBadgeClasses } from '../../resources/js/utils/statusColors';

describe('statusBadgeClasses', () => {
    it.each([
        ['success', 'bg-success-light text-base-primary'],
        ['info', 'bg-info-light text-base-primary'],
        ['warning', 'bg-warning-light text-base-primary'],
        ['danger', 'bg-error-light text-base-primary'],
        ['gray', 'bg-base-secondary/10 text-base-secondary'],
        ['primary', 'bg-primary-light text-base-primary'],
    ])('gives the %s badge its semantic classes', (color, classes) => {
        expect(statusBadgeClasses(color)).toBe(classes);
    });

    // Names of Object.prototype members are not colors either
    it.each(['purple', '', 'toString', '__proto__', 'constructor', null, undefined])(
        'shows %s as gray',
        (color) => {
            expect(statusBadgeClasses(color)).toBe('bg-base-secondary/10 text-base-secondary');
        },
    );
});

describe('pickColor', () => {
    const colors = { registered: 'info', withdrawn: 'gray' } as const;

    it('looks the key up in the map', () => {
        expect(pickColor(colors, 'registered')).toBe('info');
    });

    it('is gray for a key the map does not own', () => {
        expect(pickColor(colors, 'unknown')).toBe('gray');
        expect(pickColor(colors, 'hasOwnProperty')).toBe('gray');
    });
});
