<script setup lang="ts">
import { Check } from '@lucide/vue';

/**
 * A checkbox drawn like the core's FormCheckbox, for boxes that need what FormCheckbox does not
 * hand to its input: `required`, `name`, `aria-invalid` and `aria-describedby` reach the native
 * input (kept focusable, visually hidden), never the label. The label is the default slot, links
 * included, on a 44 px tap area.
 */
defineOptions({ inheritAttrs: false });

withDefaults(
    defineProps<{
        id: string;
        disabled?: boolean;
    }>(),
    { disabled: false }
);

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <label
        :for="id"
        class="group inline-flex min-h-[44px] items-start gap-3 py-2"
        :class="disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'"
    >
        <input
            :id="id"
            v-model="checked"
            v-bind="$attrs"
            type="checkbox"
            :disabled="disabled"
            class="peer sr-only"
        />
        <span
            data-testid="checkbox-box"
            aria-hidden="true"
            class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-2 transition-all duration-150 peer-focus-visible:ring-2 peer-focus-visible:ring-primary-500 peer-focus-visible:ring-offset-2 dark:peer-focus-visible:ring-offset-neutral-900"
            :class="[
                checked
                    ? 'border-primary-600 bg-primary-600 dark:border-primary-500 dark:bg-primary-500'
                    : 'border-neutral-300 bg-surface dark:border-neutral-600',
                !disabled &&
                    !checked &&
                    'group-hover:border-primary-400 dark:group-hover:border-primary-500',
                !disabled &&
                    checked &&
                    'group-hover:bg-primary-700 dark:group-hover:bg-primary-400',
            ]"
        >
            <Check
                class="h-3 w-3 text-white transition-all duration-150"
                :class="checked ? 'scale-100 opacity-100' : 'scale-0 opacity-0'"
                :stroke-width="3"
            />
        </span>
        <span class="select-none text-sm">
            <slot />
        </span>
    </label>
</template>
