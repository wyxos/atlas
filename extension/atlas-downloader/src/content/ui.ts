const PAGE_MARKER_STYLE_ID = 'atlas-downloader-page-markers';

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

      const finish = (result: DialogChoice) => {
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

      requestAnimationFrame(() => {
        confirm.focus();
      });
    });
}

export function ensurePageMarkerStyles() {
  if (document.getElementById(PAGE_MARKER_STYLE_ID)) {
    return;
  }

  const style = document.createElement('style');
  style.id = PAGE_MARKER_STYLE_ID;
  style.textContent = `
[data-atlas-marked="1"][data-atlas-state="exists"] {
  outline: 4px solid rgba(148, 163, 184, 0.5) !important;
  outline-offset: -4px !important;
}
[data-atlas-marked="1"][data-atlas-state="downloaded"] {
  outline: 4px solid rgba(34, 197, 94, 0.85) !important;
  outline-offset: -4px !important;
}
[data-atlas-marked="1"][data-atlas-state="blacklisted"] {
  outline: 4px solid rgba(239, 68, 68, 0.9) !important;
  outline-offset: -4px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="love"] {
  outline: 4px solid rgba(239, 68, 68, 0.9) !important;
  outline-offset: -4px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="like"] {
  outline: 4px solid rgba(56, 189, 248, 0.9) !important;
  outline-offset: -4px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="funny"] {
  outline: 4px solid rgba(234, 179, 8, 0.95) !important;
  outline-offset: -4px !important;
}
[data-atlas-marked="1"][data-atlas-state="reacted"][data-atlas-reaction="dislike"] {
  outline: 4px solid rgba(71, 85, 105, 0.95) !important;
  outline-offset: -4px !important;
}
`;

  (document.head || document.documentElement).appendChild(style);
}
