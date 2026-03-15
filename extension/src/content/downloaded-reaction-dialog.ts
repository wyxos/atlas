const ROOT_ATTR = 'data-atlas-downloaded-reaction-dialog';

export type DownloadedReactionChoice = 'react' | 'redownload' | 'cancel';

type DownloadedReactionDialog = {
    destroy: () => void;
    prompt: () => Promise<DownloadedReactionChoice>;
};

export function createDownloadedReactionDialog(): DownloadedReactionDialog {
    let root: HTMLDivElement | null = null;
    let resolveChoice: ((choice: DownloadedReactionChoice) => void) | null = null;
    let pendingPrompt: Promise<DownloadedReactionChoice> | null = null;
    let primaryButton: HTMLButtonElement | null = null;

    const closeDialog = (): void => {
        if (root === null) {
            return;
        }

        root.remove();
        window.removeEventListener('keydown', handleKeyDown, true);
    };

    const finish = (choice: DownloadedReactionChoice): void => {
        const resolver = resolveChoice;
        resolveChoice = null;
        pendingPrompt = null;
        closeDialog();
        resolver?.(choice);
    };

    const handleKeyDown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            event.preventDefault();
            finish('cancel');
        }
    };

    function createActionButton(label: string, background: string, color: string, onClick: () => void): HTMLButtonElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        button.style.border = '0';
        button.style.borderRadius = '10px';
        button.style.padding = '10px 14px';
        button.style.background = background;
        button.style.color = color;
        button.style.fontSize = '13px';
        button.style.fontWeight = '600';
        button.style.cursor = 'pointer';
        button.addEventListener('click', onClick);

        return button;
    }

    function ensureRoot(): HTMLDivElement {
        if (root !== null) {
            return root;
        }

        const backdrop = document.createElement('div');
        backdrop.setAttribute(ROOT_ATTR, '1');
        backdrop.style.position = 'fixed';
        backdrop.style.inset = '0';
        backdrop.style.zIndex = '2147483647';
        backdrop.style.display = 'flex';
        backdrop.style.alignItems = 'center';
        backdrop.style.justifyContent = 'center';
        backdrop.style.padding = '24px';
        backdrop.style.background = 'rgba(0, 0, 0, 0.52)';
        backdrop.style.backdropFilter = 'blur(3px)';

        const dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.style.width = 'min(520px, 100%)';
        dialog.style.maxWidth = '100%';
        dialog.style.borderRadius = '14px';
        dialog.style.border = '1px solid rgba(255, 255, 255, 0.12)';
        dialog.style.background = '#111827';
        dialog.style.boxShadow = '0 24px 60px rgba(0, 0, 0, 0.35)';
        dialog.style.color = '#f9fafb';
        dialog.style.fontFamily = 'ui-sans-serif, system-ui, sans-serif';
        dialog.style.padding = '20px';

        const title = document.createElement('div');
        title.textContent = 'Already saved in Atlas';
        title.style.fontSize = '16px';
        title.style.fontWeight = '700';

        const message = document.createElement('div');
        message.textContent = 'This file is already downloaded. Update the reaction, pull down a fresh copy, or leave everything as-is.';
        message.style.marginTop = '10px';
        message.style.fontSize = '14px';
        message.style.lineHeight = '1.5';
        message.style.color = '#d1d5db';

        const actions = document.createElement('div');
        actions.style.display = 'flex';
        actions.style.flexWrap = 'wrap';
        actions.style.justifyContent = 'flex-end';
        actions.style.gap = '10px';
        actions.style.marginTop = '18px';

        const cancelButton = createActionButton('Leave it as-is', 'rgba(255,255,255,0.08)', '#f3f4f6', () => {
            finish('cancel');
        });
        const reactButton = createActionButton('Update reaction', '#2563eb', '#eff6ff', () => {
            finish('react');
        });
        const redownloadButton = createActionButton('Update and download again', '#14b8a6', '#042f2e', () => {
            finish('redownload');
        });

        primaryButton = reactButton;
        actions.append(cancelButton, reactButton, redownloadButton);
        dialog.append(title, message, actions);
        backdrop.appendChild(dialog);

        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) {
                finish('cancel');
            }
        });

        root = backdrop;
        return backdrop;
    }

    function prompt(): Promise<DownloadedReactionChoice> {
        if (pendingPrompt !== null) {
            return pendingPrompt;
        }

        const backdrop = ensureRoot();
        if (!document.body.contains(backdrop)) {
            document.body.appendChild(backdrop);
        }

        primaryButton?.focus();
        window.removeEventListener('keydown', handleKeyDown, true);
        window.addEventListener('keydown', handleKeyDown, true);

        pendingPrompt = new Promise((resolve) => {
            resolveChoice = resolve;
        });

        return pendingPrompt;
    }

    function destroy(): void {
        if (resolveChoice !== null) {
            finish('cancel');
        } else {
            closeDialog();
        }

        root = null;
        primaryButton = null;
    }

    return {
        destroy,
        prompt,
    };
}
