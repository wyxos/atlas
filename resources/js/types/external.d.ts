// Stubs for backend action endpoints used throughout the front-end tests/components.
type ActionHandler = ((...args: any[]) => Promise<any>) & {
    url: (...args: any[]) => string;
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

declare module '@wyxos/vibe' {
    export class Masonry {
        remove?: (item: MasonryItem) => void;
        removeMany?: (items: MasonryItem[]) => Promise<void> | void;
        restore?: (item: MasonryItem, index: number) => Promise<void> | void;
        restoreMany?: (items: MasonryItem[], indices: number[]) => Promise<void> | void;
        reset?: () => void;
        loadPage?: (page: number) => Promise<void> | void;
        currentPage?: number;
    }

    export interface MasonryItem {
        id: number | string;
        width?: number;
        height?: number;
        page?: number;
        key?: string;
        index?: number;
        src?: string;
        thumbnail?: string;
        originalUrl?: string;
        [key: string]: unknown;
    }
}
