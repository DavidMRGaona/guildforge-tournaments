import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import CheckboxField from '../../resources/js/components/form/CheckboxField.vue';

function mountField(
    props: { modelValue?: boolean; disabled?: boolean } = {},
    attrs: Record<string, unknown> = {}
): VueWrapper {
    return mount(CheckboxField, {
        props: { id: 'consent', modelValue: false, ...props },
        attrs,
        slots: { default: 'Acepto la <a href="/privacidad">política</a>' },
    });
}

describe('CheckboxField', () => {
    it('keeps the native box focusable but hidden, and draws the site box instead', () => {
        const wrapper = mountField();

        const input = wrapper.get('input#consent');
        expect(input.attributes('type')).toBe('checkbox');
        expect(input.classes()).toContain('sr-only');
        expect(wrapper.get('[data-testid="checkbox-box"]').attributes('aria-hidden')).toBe('true');
    });

    it('names the box with its label, link included, on a 44 px tap area', () => {
        const wrapper = mountField();

        const label = wrapper.get('label[for="consent"]');
        expect(label.text()).toBe('Acepto la política');
        expect(label.find('a').attributes('href')).toBe('/privacidad');
        expect(label.classes()).toContain('min-h-[44px]');
    });

    it('hands name, required and aria attributes to the input, not to the label', () => {
        const wrapper = mountField(
            {},
            {
                name: 'gdpr_consent',
                required: true,
                'aria-invalid': 'true',
                'aria-describedby': 'consent-error',
            }
        );

        const input = wrapper.get('input#consent');
        expect(input.attributes('name')).toBe('gdpr_consent');
        expect(input.attributes('required')).toBeDefined();
        expect(input.attributes('aria-invalid')).toBe('true');
        expect(input.attributes('aria-describedby')).toBe('consent-error');
        const label = wrapper.get('label');
        expect(label.attributes('aria-invalid')).toBeUndefined();
        expect(label.attributes('aria-describedby')).toBeUndefined();
    });

    it('updates the model and shows the tick when checked', async () => {
        const wrapper = mountField();

        await wrapper.get('input#consent').setValue(true);

        expect(wrapper.emitted('update:modelValue')).toEqual([[true]]);
        await wrapper.setProps({ modelValue: true });
        expect((wrapper.get('input#consent').element as HTMLInputElement).checked).toBe(true);
        expect(wrapper.get('[data-testid="checkbox-box"]').classes()).toContain('bg-primary');
    });

    it('cannot be changed while disabled', () => {
        const wrapper = mountField({ disabled: true });

        expect(wrapper.get('input#consent').attributes('disabled')).toBeDefined();
        expect(wrapper.get('label').classes()).toContain('cursor-not-allowed');
    });
});
