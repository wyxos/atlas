const PAGE_MARKER_STYLE_ID = 'atlas-downloader-page-markers';
const DUPLICATE_MODAL_STYLE_ID = 'atlas-downloader-duplicate-modal-style';
const DUPLICATE_MODAL_ID = 'atlas-downloader-duplicate-modal';
let closeDuplicateModal: (() => void) | null = null;

export type DialogChoice = 'confirm' | 'cancel' | 'alternate';

export type DialogOptions = {
  title: string;
  message: string;
  confirmLabel: string;
  cancelLabel?: string;
  alternateLabel?: string;
  danger?: boolean;
};

export type DialogChooser = (options: DialogOptions) => Promise<DialogChoice>;

export function createToastFn(container: HTMLElement) {
  return function showToast(message: string, tone: 'info' | 'danger' = 'info') {
    const toast = document.createElement('div');
    toast.className = `atlas-downloader-toast${tone === 'danger' ? ' danger' : ''}`;
    toast.textContent = message;
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
      toast.classList.remove('show');
      toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }, 2600);
  };
}

export function createDialogChooser(root: HTMLElement): DialogChooser {
  return (options: DialogOptions): Promise<DialogChoice> =>
    new Promise((resolve) => {
      const backdrop = document.createElement('div');
      backdrop.className = 'atlas-downloader-dialog-backdrop';

      const panel = document.createElement('div');
      panel.className = `atlas-downloader-dialog${options.danger ? ' danger' : ''}`.trim();
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-modal', 'true');
      panel.setAttribute('aria-label', options.title);

      const titleNode = document.createElement('h3');
      titleNode.className = 'atlas-downloader-dialog-title';
      titleNode.textContent = options.title;

      const messageNode = document.createElement('p');
      messageNode.className = 'atlas-downloader-dialog-message';
      messageNode.textContent = options.message;

      const actions = document.createElement('div');
      actions.className = 'atlas-downloader-dialog-actions';

      let finished = false;
      const handleEscape = (event: KeyboardEvent) => {
        if (event.key !== 'Escape') {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        finish('cancel');
      };
      const finish = (result: DialogChoice) => {
        if (finished) {
          return;
        }

        finished = true;
        document.removeEventListener('keydown', handleEscape, true);
        backdrop.remove();
        resolve(result);
      };

      if (options.alternateLabel) {
        const alternate = document.createElement('button');
        alternate.type = 'button';
        alternate.className = 'atlas-downloader-dialog-btn secondary';
        alternate.textContent = options.alternateLabel;
        alternate.addEventListener('click', () => finish('alternate'));
        actions.appendChild(alternate);
      }

      const cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'atlas-downloader-dialog-btn';
      cancel.textContent = options.cancelLabel || 'Cancel';
      cancel.addEventListener('click', () => finish('cancel'));
      actions.appendChild(cancel);

      const confirm = document.createElement('button');
      confirm.type = 'button';
      confirm.className = `atlas-downloader-dialog-btn primary${options.danger ? ' danger' : ''}`.trim();
      confirm.textContent = options.confirmLabel;
      confirm.addEventListener('click', () => finish('confirm'));
      actions.appendChild(confirm);

      panel.appendChild(titleNode);
      panel.appendChild(messageNode);
      panel.appendChild(actions);
      backdrop.appendChild(panel);
      root.appendChild(backdrop);
      backdrop.addEventListener('click', (event) => {
        if (event.target === backdrop) {
          finish('cancel');
        }
      });
      document.addEventListener('keydown', handleEscape, true);

      requestAnimationFrame(() => {
        confirm.focus();
      });
    });
}

export function showDuplicateTabBlockedModal(duplicateUrl: string) {
  ensureDuplicateModalStyles();

  closeDuplicateModal?.();
  closeDuplicateModal = null;
  document.getElementById(DUPLICATE_MODAL_ID)?.remove();

  const backdrop = document.createElement('div');
  backdrop.id = DUPLICATE_MODAL_ID;
  backdrop.className = 'atlas-downloader-duplicate-backdrop';

  const panel = document.createElement('div');
  panel.className = 'atlas-downloader-duplicate-dialog';
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'true');
  panel.setAttribute('aria-label', 'Duplicate tab blocked');

  const title = document.createElement('h3');
  title.className = 'atlas-downloader-duplicate-title';
  title.textContent = 'Duplicate tab blocked';

  const message = document.createElement('p');
  message.className = 'atlas-downloader-duplicate-message';
  message.textContent = 'This page is already open in another tab.';

  const url = (duplicateUrl || '').trim();
  const urlNode = document.createElement('code');
  urlNode.className = 'atlas-downloader-duplicate-url';
  if (url) {
    urlNode.textContent = url;
  }

  const actions = document.createElement('div');
  actions.className = 'atlas-downloader-duplicate-actions';

  const ok = document.createElement('button');
  ok.type = 'button';
  ok.className = 'atlas-downloader-duplicate-btn';
  ok.textContent = 'OK';
  actions.appendChild(ok);

  let finished = false;
  const finish = () => {
    if (finished) {
      return;
    }

    finished = true;
    document.removeEventListener('keydown', handleEscape, true);
    backdrop.remove();
    if (closeDuplicateModal === finish) {
      closeDuplicateModal = null;
    }
  };

  const handleEscape = (event: KeyboardEvent) => {
    if (event.key !== 'Escape') {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    finish();
  };

  ok.addEventListener('click', () => finish());
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) {
      finish();
    }
  });

  panel.appendChild(title);
  panel.appendChild(message);
  if (url) {
    panel.appendChild(urlNode);
  }
  panel.appendChild(actions);
  backdrop.appendChild(panel);

  (document.body || document.documentElement).appendChild(backdrop);
  document.addEventListener('keydown', handleEscape, true);
  closeDuplicateModal = finish;

  requestAnimationFrame(() => {
    ok.focus();
  });
}

export function ensurePageMarkerStyles() {
  if (document.getElementById(PAGE_MARKER_STYLE_ID)) {
    return;
  }

  const style = document.createElement('style');
  style.id = PAGE_MARKER_STYLE_ID;
  style.textContent = `
[data-atlas-marker-rail="1"] {
  position: absolute;
  pointer-events: none;
  z-index: 1;
}
[data-atlas-marker-host-position="1"] {
  position: relative !important;
}
[data-atlas-marker-rail="1"].atlas-downloader-marker-rail-top {
  top: 0;
  left: 0;
  right: 0;
  height: 3px;
}
[data-atlas-marker-rail="1"].atlas-downloader-marker-rail-right {
  top: 0;
  right: 0;
  bottom: 0;
  width: 3px;
}
[data-atlas-marker-rail="1"].atlas-downloader-marker-rail-bottom {
  bottom: 0;
  left: 0;
  right: 0;
  height: 3px;
}
[data-atlas-marker-rail="1"].atlas-downloader-marker-rail-left {
  top: 0;
  left: 0;
  bottom: 0;
  width: 3px;
}
#atlas-downloader-page-visited-badge {
  position: fixed;
  top: 14px;
  right: 14px;
  z-index: 2147483646;
  pointer-events: none;
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(239, 68, 68, 0.7);
  background: rgba(185, 28, 28, 0.94);
  color: #fee2e2;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  box-shadow: 0 8px 18px rgba(2, 6, 23, 0.42);
}
.atlas-downloader-inline-badge {
  position: absolute;
  right: 3px;
  bottom: 3px;
  z-index: 2;
  pointer-events: none;
}
a[href] img[data-atlas-state="reacted"],
a[href] video[data-atlas-state="reacted"] {
  opacity: 0.3;
}
.atlas-downloader-reaction-badge {
  width: 50px;
  height: 50px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(15, 23, 42, 0.65);
  box-shadow: 0 2px 8px rgba(2, 6, 23, 0.45);
  background: rgba(15, 23, 42, 0.92);
  color: #fff;
}
.atlas-downloader-reaction-badge svg {
  width: 28px;
  height: 28px;
  fill: none !important;
  stroke: currentColor !important;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.atlas-downloader-reaction-badge.like {
  background: rgba(14, 116, 144, 0.95);
}
.atlas-downloader-reaction-badge.love {
  background: rgba(185, 28, 28, 0.95);
}
.atlas-downloader-reaction-badge.funny {
  background: rgba(161, 98, 7, 0.95);
}
.atlas-downloader-reaction-badge.dislike {
  background: rgba(51, 65, 85, 0.95);
}
.atlas-downloader-open-tab-badge {
  width: 50px;
  height: 50px;
  border-radius: 12px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(6, 78, 59, 0.75);
  box-shadow: 0 2px 8px rgba(2, 6, 23, 0.45);
  background: rgba(5, 150, 105, 0.95);
  color: #ecfdf5;
}
.atlas-downloader-open-tab-badge svg {
  width: 28px;
  height: 28px;
  fill: none !important;
  stroke: currentColor !important;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
`;

  (document.head || document.documentElement).appendChild(style);
}

function ensureDuplicateModalStyles() {
  if (document.getElementById(DUPLICATE_MODAL_STYLE_ID)) {
    return;
  }

  const style = document.createElement('style');
  style.id = DUPLICATE_MODAL_STYLE_ID;
  style.textContent = `
.atlas-downloader-duplicate-backdrop {
  position: fixed;
  inset: 0;
  z-index: 2147483646;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(2, 6, 23, 0.62);
}
.atlas-downloader-duplicate-dialog {
  width: min(460px, calc(100vw - 32px));
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.45);
  background: #0f172a;
  box-shadow: 0 18px 46px rgba(2, 6, 23, 0.45);
  padding: 16px;
  color: #e2e8f0;
}
.atlas-downloader-duplicate-title {
  margin: 0 0 10px;
  font-size: 16px;
  line-height: 1.25;
  font-weight: 700;
  color: #f8fafc;
}
.atlas-downloader-duplicate-message {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
}
.atlas-downloader-duplicate-url {
  display: block;
  margin-top: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid rgba(56, 189, 248, 0.45);
  background: rgba(15, 23, 42, 0.86);
  color: #bae6fd;
  font-size: 12px;
  line-height: 1.4;
  overflow-wrap: anywhere;
}
.atlas-downloader-duplicate-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
.atlas-downloader-duplicate-btn {
  height: 32px;
  border: 0;
  border-radius: 8px;
  background: linear-gradient(180deg, #38bdf8 0%, #0284c7 100%);
  color: #f8fafc;
  padding: 0 16px;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
}
.atlas-downloader-duplicate-btn:focus-visible {
  outline: 2px solid rgba(125, 211, 252, 0.9);
  outline-offset: 2px;
}
`;

  (document.head || document.documentElement).appendChild(style);
}
