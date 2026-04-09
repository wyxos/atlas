const CIVITAI_HOST_PATTERN = /(^|\.)civitai\.com$/i;
const URN_PREFIX = 'civitai:';
const CTA_TEXT = 'Open in Atlas';
const CTA_PENDING_TEXT = 'Opening...';
const CTA_SUCCESS_TEXT = 'Opened';
const CTA_FAILURE_TEXT = 'Failed';
const CTA_SELECTOR = '[data-atlas-civitai-model-browse-cta]';

type CivitAiModelReference = {
    modelId: number;
    modelVersionId: number | null;
    key: string;
};

type RuntimeOpenResponse = {
    ok?: boolean;
};

let isInstalled = false;

function isCivitAiHostname(hostname: string = window.location.hostname): boolean {
    return CIVITAI_HOST_PATTERN.test(hostname);
}

function parsePositiveInteger(value: string | null | undefined): number | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '' || /^\d+$/.test(trimmed) === false) {
        return null;
    }

    const parsed = Number(trimmed);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

function resolveActionHost(prefixCode: HTMLElement): HTMLElement | null {
    const directParent = prefixCode.parentElement;
    if (!(directParent instanceof HTMLElement)) {
        return null;
    }

    if (directParent.parentElement instanceof HTMLElement) {
        return directParent.parentElement;
    }

    return directParent;
}

function resolveModelReference(actionHost: ParentNode): CivitAiModelReference | null {
    const values = Array.from(actionHost.querySelectorAll('code'))
        .map((code) => code.textContent?.trim() ?? '')
        .filter((value) => value !== '');

    for (let index = 0; index < values.length; index += 1) {
        if (values[index]?.toLowerCase() !== URN_PREFIX) {
            continue;
        }

        const modelId = parsePositiveInteger(values[index + 1]);
        if (modelId === null) {
            continue;
        }

        const modelVersionId = values[index + 2] === '@'
            ? parsePositiveInteger(values[index + 3])
            : null;

        return {
            modelId,
            modelVersionId,
            key: modelVersionId === null ? `${modelId}` : `${modelId}@${modelVersionId}`,
        };
    }

    return null;
}

function setButtonState(
    button: HTMLButtonElement,
    label: string,
    disabled: boolean,
): void {
    button.textContent = label;
    button.disabled = disabled;
    button.style.opacity = disabled ? '0.7' : '1';
    button.style.cursor = disabled ? 'default' : 'pointer';
}

function requestOpenCivitAiModelTab(reference: CivitAiModelReference): Promise<RuntimeOpenResponse | null> {
    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage({
                type: 'ATLAS_OPEN_CIVITAI_MODEL_TAB',
                modelId: reference.modelId,
                modelVersionId: reference.modelVersionId,
            }, (response: unknown) => {
                if (chrome.runtime.lastError) {
                    resolve(null);
                    return;
                }

                resolve(response && typeof response === 'object' ? response as RuntimeOpenResponse : null);
            });
        } catch {
            resolve(null);
        }
    });
}

function createCtaButton(reference: CivitAiModelReference): HTMLButtonElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.atlasCivitaiModelBrowseCta = reference.key;
    button.style.cssText = [
        'appearance:none',
        'display:inline-flex',
        'align-items:center',
        'justify-content:center',
        'min-height:24px',
        'padding:4px 10px',
        'border-radius:999px',
        'border:1px solid rgba(34, 139, 230, 0.3)',
        'background:rgba(34, 139, 230, 0.08)',
        'color:#228be6',
        'font-size:12px',
        'font-weight:600',
        'line-height:1.2',
        'white-space:nowrap',
    ].join(';');
    setButtonState(button, CTA_TEXT, false);

    button.addEventListener('click', () => {
        setButtonState(button, CTA_PENDING_TEXT, true);

        void requestOpenCivitAiModelTab(reference)
            .then((response) => {
                setButtonState(button, response?.ok === true ? CTA_SUCCESS_TEXT : CTA_FAILURE_TEXT, true);
            })
            .catch(() => {
                setButtonState(button, CTA_FAILURE_TEXT, true);
            })
            .finally(() => {
                window.setTimeout(() => {
                    setButtonState(button, CTA_TEXT, false);
                }, 1500);
            });
    });

    return button;
}

function applyBrowseCta(actionHost: HTMLElement): void {
    const reference = resolveModelReference(actionHost);
    if (reference === null) {
        return;
    }

    const existingButton = actionHost.querySelector(CTA_SELECTOR);
    if (existingButton instanceof HTMLButtonElement) {
        if (existingButton.dataset.atlasCivitaiModelBrowseCta === reference.key) {
            return;
        }

        existingButton.remove();
    }

    actionHost.appendChild(createCtaButton(reference));
}

function syncBrowseCtas(root: ParentNode): void {
    const seen = new Set<HTMLElement>();
    const directCodes = root instanceof HTMLElement && root.tagName === 'CODE' ? [root] : [];

    for (const code of [...directCodes, ...Array.from(root.querySelectorAll('code'))]) {
        if (!(code instanceof HTMLElement) || code.textContent?.trim().toLowerCase() !== URN_PREFIX) {
            continue;
        }

        const actionHost = resolveActionHost(code);
        if (!(actionHost instanceof HTMLElement) || seen.has(actionHost)) {
            continue;
        }

        seen.add(actionHost);
        applyBrowseCta(actionHost);
    }
}

export function installCivitAiModelBrowseCtas(): void {
    if (isInstalled || !isCivitAiHostname()) {
        return;
    }

    isInstalled = true;
    syncBrowseCtas(document);

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type !== 'childList') {
                continue;
            }

            for (const addedNode of mutation.addedNodes) {
                if (!(addedNode instanceof Element)) {
                    continue;
                }

                syncBrowseCtas(addedNode);
            }
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
    });
}
