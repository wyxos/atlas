import type { AtlasRequestStatus } from './atlas-request-log';

type AtlasRequestFailureDetails = {
    endpoint: string;
    method: string;
    requestPayload: unknown;
    responsePayload: unknown;
    status: AtlasRequestStatus;
};

const TOAST_CONTAINER_ID = 'atlas-extension-request-failure-toast-container';
const TOAST_ATTR = 'data-atlas-request-failure-toast';
const TOAST_DURATION_MS = 5000;

let activeToast: HTMLDivElement | null = null;
let activeToastTimer: number | null = null;

function formatFailureLabel(status: AtlasRequestStatus): string {
    if (typeof status === 'number') {
        return String(status);
    }

    return status.replace(/_/g, ' ');
}

function toastMessage(status: AtlasRequestStatus): string {
    return `Atlas request failed (${formatFailureLabel(status)}). Check the console for details.`;
}

function toastRoot(): HTMLElement | null {
    return document.body ?? document.documentElement ?? null;
}

function ensureToastContainer(): HTMLDivElement | null {
    const root = toastRoot();
    if (root === null) {
        return null;
    }

    const existing = document.getElementById(TOAST_CONTAINER_ID);
    if (existing instanceof HTMLDivElement) {
        return existing;
    }

    const container = document.createElement('div');
    container.id = TOAST_CONTAINER_ID;
    container.style.position = 'fixed';
    container.style.right = '16px';
    container.style.bottom = '16px';
    container.style.zIndex = '2147483647';
    container.style.display = 'flex';
    container.style.alignItems = 'flex-end';
    container.style.justifyContent = 'flex-end';
    container.style.pointerEvents = 'none';
    root.appendChild(container);

    return container;
}

function clearToastTimer(): void {
    if (activeToastTimer !== null) {
        window.clearTimeout(activeToastTimer);
        activeToastTimer = null;
    }
}

function dismissActiveToast(): void {
    clearToastTimer();

    if (activeToast !== null) {
        activeToast.remove();
        activeToast = null;
    }
}

function showToast(message: string): void {
    if (typeof document === 'undefined' || typeof window === 'undefined') {
        return;
    }

    const container = ensureToastContainer();
    if (container === null) {
        return;
    }

    const toast = activeToast ?? document.createElement('div');
    toast.setAttribute(TOAST_ATTR, '1');
    toast.textContent = message;
    toast.style.maxWidth = '360px';
    toast.style.padding = '12px 14px';
    toast.style.borderRadius = '12px';
    toast.style.border = '1px solid rgba(248, 113, 113, 0.45)';
    toast.style.background = 'rgba(15, 23, 42, 0.94)';
    toast.style.boxShadow = '0 18px 40px rgba(15, 23, 42, 0.35)';
    toast.style.color = '#fee2e2';
    toast.style.fontSize = '13px';
    toast.style.lineHeight = '1.45';
    toast.style.fontFamily = 'ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
    toast.style.pointerEvents = 'auto';

    if (toast.parentElement !== container) {
        container.appendChild(toast);
    }

    activeToast = toast;
    clearToastTimer();
    activeToastTimer = window.setTimeout(() => {
        dismissActiveToast();
    }, TOAST_DURATION_MS);
}

export function clearAtlasRequestFailureFeedback(): void {
    dismissActiveToast();
    const container = document.getElementById(TOAST_CONTAINER_ID);
    container?.remove();
}

export function reportAtlasRequestFailure(details: AtlasRequestFailureDetails): void {
    console.error('[Atlas Extension] Request failed', details);
    showToast(toastMessage(details.status));
}
