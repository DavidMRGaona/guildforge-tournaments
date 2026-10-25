import type { VueWrapper } from '@vue/test-utils';

type Searchable = Pick<VueWrapper, 'findAll'>;
type Found = ReturnType<Searchable['findAll']>[number];

/** Links on the page that hold another interactive element (a button inside a link, …). */
export function nestedInteractiveLinks(wrapper: Searchable): string[] {
    return wrapper
        .findAll('a')
        .filter((link) => link.find('a, button, input, select, textarea').exists())
        .map((link) => link.text());
}

/** The link whose text is `label`, or undefined. */
export function linkWithText(wrapper: Searchable, label: string): Found | undefined {
    return wrapper.findAll('a').find((link) => link.text() === label);
}
