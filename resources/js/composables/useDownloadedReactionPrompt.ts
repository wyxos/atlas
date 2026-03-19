import { onBeforeUnmount, ref } from 'vue';

export type DownloadedReactionChoice = 'react' | 'redownload' | 'cancel';

export function useDownloadedReactionPrompt() {
    const open = ref(false);
    let pendingPrompt: Promise<DownloadedReactionChoice> | null = null;
    let resolveChoice: ((choice: DownloadedReactionChoice) => void) | null = null;

    function finish(choice: DownloadedReactionChoice): void {
        const resolver = resolveChoice;
        resolveChoice = null;
        pendingPrompt = null;
        open.value = false;
        resolver?.(choice);
    }

    function prompt(): Promise<DownloadedReactionChoice> {
        if (pendingPrompt !== null) {
            return pendingPrompt;
        }

        open.value = true;
        pendingPrompt = new Promise((resolve) => {
            resolveChoice = resolve;
        });

        return pendingPrompt;
    }

    function chooseReact(): void {
        finish('react');
    }

    function chooseRedownload(): void {
        finish('redownload');
    }

    function close(): void {
        finish('cancel');
    }

    function setOpen(value: boolean): void {
        if (!value) {
            close();
        }
    }

    onBeforeUnmount(() => {
        if (resolveChoice !== null) {
            finish('cancel');
        }
    });

    return {
        data: {
            open,
        },
        prompt,
        chooseReact,
        chooseRedownload,
        close,
        setOpen,
    };
}

export type DownloadedReactionPrompt = ReturnType<typeof useDownloadedReactionPrompt>;
