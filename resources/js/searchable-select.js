/**
 * Searchable dropdown for <x-select>.
 *
 * The native <select> stays in the DOM as the source of truth: wire:model / x-model bind to it,
 * and picking an option writes select.value then fires "change", so Livewire sees a normal select.
 * The panel is teleported to <body> so it is not clipped by modals or scrolling table wrappers.
 */

const PANEL_MAX_LIST_HEIGHT = 256;
const PANEL_MIN_WIDTH = 240;
const VIEWPORT_GUTTER = 8;

const normalize = (text) =>
    text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');

function searchableSelect({ model = null } = {}) {
    return {
        open: false,
        query: '',
        options: [],
        activeIndex: -1,
        selectedValue: '',
        selectedLabel: '',
        isPlaceholder: true,
        disabled: false,
        panelStyle: {},
        listMaxHeight: PANEL_MAX_LIST_HEIGHT,
        uid: Math.random().toString(36).slice(2, 9),

        init() {
            const select = this.$refs.select;

            select.addEventListener('change', () => this.sync());

            const observer = new MutationObserver(() => this.$nextTick(() => this.sync()));
            observer.observe(select, { childList: true, subtree: true, attributes: true, characterData: true });

            // wire:model sets option.selected directly when the server value changes; no event fires.
            if (model && this.$wire) {
                this.$watch(() => this.$wire.get(model), () => this.$nextTick(() => this.sync()));
            }

            this.reposition = () => this.position();

            this.$nextTick(() => this.sync());

            this.teardown = () => observer.disconnect();
        },

        destroy() {
            this.teardown?.();
            this.detachViewportListeners();
        },

        sync() {
            const select = this.$refs.select;

            if (! select) {
                return;
            }

            const options = [];

            for (const child of select.children) {
                if (child.tagName === 'OPTGROUP') {
                    for (const option of child.children) {
                        options.push(this.describe(option, child.label));
                    }
                } else if (child.tagName === 'OPTION') {
                    options.push(this.describe(child, null));
                }
            }

            this.options = options;
            this.disabled = select.disabled;
            this.selectedValue = select.value;

            const selected = select.selectedOptions[0];
            this.selectedLabel = selected ? selected.textContent.trim() : '';
            this.isPlaceholder = ! selected || selected.value === '';

            if (this.disabled && this.open) {
                this.close();
            }
        },

        describe(option, group) {
            const label = option.textContent.trim();

            return {
                value: option.value,
                label,
                group,
                disabled: option.disabled,
                haystack: normalize(`${group ?? ''} ${label}`),
            };
        },

        get filtered() {
            const terms = normalize(this.query).split(/\s+/).filter(Boolean);
            const matches = terms.length === 0
                ? this.options
                : this.options.filter((option) => terms.every((term) => option.haystack.includes(term)));

            return matches.map((option, index) => ({
                ...option,
                index,
                groupStart: option.group !== null && (index === 0 || matches[index - 1].group !== option.group),
            }));
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            if (this.disabled) {
                return;
            }

            this.query = '';
            this.open = true;
            this.activeIndex = this.filtered.findIndex((option) => option.value === this.selectedValue);

            this.$nextTick(() => {
                this.position();
                this.$refs.search?.focus({ preventScroll: true });
                this.scrollActiveIntoView();
            });

            window.addEventListener('scroll', this.reposition, true);
            window.addEventListener('resize', this.reposition);
        },

        close({ focusTrigger = false } = {}) {
            this.open = false;
            this.detachViewportListeners();

            if (focusTrigger) {
                this.$refs.trigger?.focus();
            }
        },

        detachViewportListeners() {
            if (this.reposition) {
                window.removeEventListener('scroll', this.reposition, true);
                window.removeEventListener('resize', this.reposition);
            }
        },

        closeOnOutsideClick(event) {
            if (this.open && ! this.$refs.trigger.contains(event.target)) {
                this.close();
            }
        },

        position() {
            const trigger = this.$refs.trigger;
            const panel = this.$refs.panel;

            if (! trigger || ! panel) {
                return;
            }

            const rect = trigger.getBoundingClientRect();
            const viewportWidth = document.documentElement.clientWidth;
            const viewportHeight = window.innerHeight;
            const width = Math.min(Math.max(rect.width, PANEL_MIN_WIDTH), viewportWidth - VIEWPORT_GUTTER * 2);
            const left = Math.min(Math.max(rect.left, VIEWPORT_GUTTER), viewportWidth - width - VIEWPORT_GUTTER);

            const searchHeight = this.$refs.search?.parentElement.offsetHeight ?? 48;
            const spaceBelow = viewportHeight - rect.bottom - VIEWPORT_GUTTER * 2;
            const spaceAbove = rect.top - VIEWPORT_GUTTER * 2;
            const wanted = PANEL_MAX_LIST_HEIGHT + searchHeight;
            const openUpward = spaceBelow < wanted && spaceAbove > spaceBelow;
            const available = openUpward ? spaceAbove : spaceBelow;

            this.listMaxHeight = Math.max(Math.min(PANEL_MAX_LIST_HEIGHT, available - searchHeight), 96);

            this.panelStyle = {
                position: 'fixed',
                left: `${left}px`,
                width: `${width}px`,
                top: openUpward ? 'auto' : `${rect.bottom + 4}px`,
                bottom: openUpward ? `${viewportHeight - rect.top + 4}px` : 'auto',
            };
        },

        choose(option) {
            if (! option || option.disabled) {
                return;
            }

            const select = this.$refs.select;

            if (select.value !== option.value) {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.sync();
            this.close({ focusTrigger: true });
        },

        chooseActive() {
            this.choose(this.filtered[this.activeIndex]);
        },

        move(step) {
            const list = this.filtered;

            if (list.length === 0) {
                return;
            }

            let index = this.activeIndex;

            for (let attempt = 0; attempt < list.length; attempt++) {
                index = (index + step + list.length) % list.length;

                if (! list[index].disabled) {
                    break;
                }
            }

            this.activeIndex = index;
            this.$nextTick(() => this.scrollActiveIntoView());
        },

        moveTo(edge) {
            const list = this.filtered;
            const ordered = edge === 'first' ? list : [...list].reverse();
            const target = ordered.find((option) => ! option.disabled);

            this.activeIndex = target ? target.index : -1;
            this.$nextTick(() => this.scrollActiveIntoView());
        },

        onQueryChanged() {
            this.activeIndex = this.filtered.findIndex((option) => ! option.disabled);
            this.$nextTick(() => this.scrollActiveIntoView());
        },

        scrollActiveIntoView() {
            this.$refs.panel?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
        },

        onTriggerKeydown(event) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                this.show();
            }
        },

        onSearchKeydown(event) {
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    this.move(1);
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    this.move(-1);
                    break;
                case 'Home':
                    event.preventDefault();
                    this.moveTo('first');
                    break;
                case 'End':
                    event.preventDefault();
                    this.moveTo('last');
                    break;
                case 'Enter':
                    event.preventDefault();
                    this.chooseActive();
                    break;
                case 'Escape':
                    // Keep the surrounding modal open: its Escape handler listens on window.
                    event.preventDefault();
                    event.stopPropagation();
                    this.close({ focusTrigger: true });
                    break;
                case 'Tab':
                    // Hand focus back to the trigger so the browser's Tab continues from there.
                    this.close({ focusTrigger: true });
                    break;
            }
        },

        optionId(index) {
            return `select-${this.uid}-option-${index}`;
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('searchableSelect', searchableSelect);
});
