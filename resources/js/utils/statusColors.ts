// Colors the server sends for statuses (Filament-style names) mapped to the project's semantic badge classes.
export type StatusColor = 'success' | 'info' | 'warning' | 'danger' | 'gray' | 'primary';

const BADGE_CLASSES: Record<StatusColor, string> = {
    success: 'bg-success-light text-success',
    info: 'bg-info-light text-info',
    warning: 'bg-warning-light text-warning',
    danger: 'bg-error-light text-error',
    gray: 'bg-muted text-base-secondary',
    primary: 'bg-primary-light text-primary',
};

export function statusBadgeClasses(color: string | null | undefined): string {
    if (color != null && Object.hasOwn(BADGE_CLASSES, color)) {
        return BADGE_CLASSES[color as StatusColor];
    }
    return BADGE_CLASSES.gray;
}

// Looks a key up in an exhaustive map; anything the map does not own is gray.
export function pickColor<K extends string>(map: Record<K, StatusColor>, key: string): StatusColor {
    return Object.hasOwn(map, key) ? map[key as K] : 'gray';
}
