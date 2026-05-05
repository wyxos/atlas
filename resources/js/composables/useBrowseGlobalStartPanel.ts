import { inject, provide, ref, type InjectionKey, type Ref } from 'vue';

export type BrowseGlobalStartPanel = {
    close: () => void;
    isOpen: Ref<boolean>;
    open: () => void;
    toggle: () => void;
};

const BrowseGlobalStartPanelKey: InjectionKey<BrowseGlobalStartPanel> = Symbol('BrowseGlobalStartPanel');

export function provideBrowseGlobalStartPanel(): BrowseGlobalStartPanel {
    const isOpen = ref(false);
    const panel = {
        close: () => {
            isOpen.value = false;
        },
        isOpen,
        open: () => {
            isOpen.value = true;
        },
        toggle: () => {
            isOpen.value = !isOpen.value;
        },
    };

    provide(BrowseGlobalStartPanelKey, panel);

    return panel;
}

export function useBrowseGlobalStartPanel(): BrowseGlobalStartPanel | null {
    return inject(BrowseGlobalStartPanelKey, null);
}
