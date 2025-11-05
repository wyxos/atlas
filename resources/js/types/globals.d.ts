import { AppPageProps } from '@/types/index';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        readonly VITE_SENTRY_DSN?: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps, AppPageProps {}
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}

declare namespace Spotify {
    interface Player {
        addListener(event: string, callback: (...args: any[]) => void): void;
        removeListener?(event: string): void;
        connect(): Promise<boolean>;
        disconnect?(): Promise<void>;
        resume(): Promise<void>;
        pause(): Promise<void>;
        seek(positionMs: number): Promise<void>;
        setVolume(volume: number): Promise<void>;
        getCurrentState(): Promise<any>;
        activateElement?(): Promise<void>;
    }
}
