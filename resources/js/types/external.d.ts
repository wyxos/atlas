// Stubs for backend action endpoints used throughout the front-end tests/components.
type ActionHandler = ((...args: unknown[]) => Promise<unknown>) & {
    url: (...args: unknown[]) => string;
    definition?: { url: string };
};

declare module '@/actions/App/Http/Controllers/FilesController' {
    export const index: ActionHandler;
    export const show: ActionHandler;
    export const destroy: ActionHandler;
    export const deleteAll: ActionHandler;
    export const incrementPreview: ActionHandler;
    export const batchIncrementPreview: ActionHandler;
    export const batchPerformAutoDislike: ActionHandler;
    export const incrementSeen: ActionHandler;
}

declare module '@/actions/App/Http/Controllers/BrowseController' {
    export const index: ActionHandler;
    export const services: ActionHandler;
}

declare module '@/actions/App/Http/Controllers/TabController' {
    export const index: ActionHandler;
    export const items: ActionHandler;
    export const store: ActionHandler;
    export const update: ActionHandler;
    export const destroy: ActionHandler;
    export const setActive: ActionHandler;
    export const deleteAll: ActionHandler;
}

declare module '@/actions/App/Http/Controllers/FileReactionController' {
    export const store: ActionHandler;
    export const batchShow: ActionHandler;
}

declare module '@/actions/App/Http/Controllers/UsersController' {
    export const index: ActionHandler;
    export const destroy: ActionHandler;
}

declare module '@/actions/App/Http/Controllers/ProfileController' {
    export const updatePassword: ActionHandler;
    export const deleteAccount: ActionHandler;
}

// Vibe now exports MasonryInstance type - this augmentation makes it available
// Once Vibe is rebuilt and the type is properly exported, this can be removed
declare module '@wyxos/vibe' {
    export interface MasonryInstance {
        cancelLoad: () => void;
        context: any;
        containerHeight: number;
        containerWidth: number;
        currentBreakpoint: string;
        currentPage: number | string | null;
        destroy: () => void;
        hasReachedEnd: boolean;
        initialize: (items: any[], page: number | string, next: number | string | null) => void;
        isInitialized: boolean;
        isLoading: boolean;
        loadError: Error | null;
        loadNext: () => Promise<void>;
        loadPage: (page: number | string) => Promise<void>;
        paginationHistory: Array<number | string | null>;
        refreshCurrentPage: () => Promise<void>;
        refreshLayout: (items: any[]) => void;
        remove: (item: any) => void;
        removeAll: () => void;
        removeMany: (items: any[]) => Promise<void> | void;
        reset: () => void;
        restore: (item: any, index: number) => Promise<void> | void;
        restoreMany: (items: any[], indices: number[]) => Promise<void> | void;
        scrollTo: (position: number) => void;
        scrollToTop: () => void;
        setContext: (val: any) => void;
        setFixedDimensions: (dimensions: { width: number; height: number } | null) => void;
        totalItems: number;
    }
}
