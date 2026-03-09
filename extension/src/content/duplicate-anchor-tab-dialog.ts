const ROOT_ATTR = 'data-atlas-duplicate-tab-dialog';

type DuplicateAnchorTabDialog = {
    destroy: () => void;
    hide: () => void;
    show: (url: string) => void;
};

function truncateUrl(value: string): string {
    return value.length <= 140 ? value : `${value.slice(0, 137)}...`;
}

export function createDuplicateAnchorTabDialog(): DuplicateAnchorTabDialog {
    let root: HTMLDivElement | null = null;
    let urlText: HTMLDivElement | null = null;

    const handleKeyDown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape') {
            hide();
        }
    };

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
        dialog.style.width = 'min(480px, 100%)';
        dialog.style.maxWidth = '100%';
        dialog.style.borderRadius = '14px';
        dialog.style.border = '1px solid rgba(255, 255, 255, 0.12)';
        dialog.style.background = '#111827';
        dialog.style.boxShadow = '0 24px 60px rgba(0, 0, 0, 0.35)';
        dialog.style.color = '#f9fafb';
        dialog.style.fontFamily = 'ui-sans-serif, system-ui, sans-serif';
        dialog.style.padding = '20px';

        const title = document.createElement('div');
        title.textContent = 'Link already open';
        title.style.fontSize = '16px';
        title.style.fontWeight = '700';

        const message = document.createElement('div');
        message.textContent = 'This link is already open in another tab.';
        message.style.marginTop = '10px';
        message.style.fontSize = '14px';
        message.style.lineHeight = '1.5';
        message.style.color = '#d1d5db';

        urlText = document.createElement('div');
        urlText.style.marginTop = '14px';
        urlText.style.padding = '10px 12px';
        urlText.style.borderRadius = '10px';
        urlText.style.background = 'rgba(255, 255, 255, 0.06)';
        urlText.style.fontSize = '12px';
        urlText.style.lineHeight = '1.5';
        urlText.style.whiteSpace = 'nowrap';
        urlText.style.overflow = 'hidden';
        urlText.style.textOverflow = 'ellipsis';
        urlText.style.color = '#93c5fd';

        const actions = document.createElement('div');
        actions.style.display = 'flex';
        actions.style.justifyContent = 'flex-end';
        actions.style.marginTop = '16px';

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.textContent = 'Close';
        closeButton.style.border = '0';
        closeButton.style.borderRadius = '10px';
        closeButton.style.padding = '8px 12px';
        closeButton.style.background = '#2563eb';
        closeButton.style.color = '#eff6ff';
        closeButton.style.fontSize = '13px';
        closeButton.style.fontWeight = '600';
        closeButton.style.cursor = 'pointer';
        closeButton.addEventListener('click', () => {
            hide();
        });

        actions.appendChild(closeButton);
        dialog.append(title, message, urlText, actions);
        backdrop.appendChild(dialog);

        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) {
                hide();
            }
        });

        root = backdrop;
        return backdrop;
    }

    function hide(): void {
        if (root === null) {
            return;
        }

        root.remove();
        window.removeEventListener('keydown', handleKeyDown, true);
    }

    function show(url: string): void {
        const backdrop = ensureRoot();
        if (urlText !== null) {
            urlText.textContent = truncateUrl(url);
            urlText.title = url;
        }

        if (!document.body.contains(backdrop)) {
            document.body.appendChild(backdrop);
        }

        window.removeEventListener('keydown', handleKeyDown, true);
        window.addEventListener('keydown', handleKeyDown, true);
    }

    function destroy(): void {
        hide();
        root = null;
        urlText = null;
    }

    return {
        destroy,
        hide,
        show,
    };
}
