type SendMessage = (message: unknown, callback: (response: unknown) => void) => void;
type ShowToast = (message: string, tone?: 'info' | 'danger') => void;

export type AtlasRequestState = 'executing' | 'completed' | 'failed';

export type AtlasRequestTraceEntry = {
  id: number;
  messageType: string;
  path: string;
  state: AtlasRequestState;
  startedAt: number;
  finishedAt: number | null;
};

type RequestTracker = {
  onStart?: (entry: AtlasRequestTraceEntry) => void;
  onFinish?: (entry: AtlasRequestTraceEntry) => void;
};

const REQUEST_PATHS: Record<string, string> = {
  'atlas-download': '/api/extension/files',
  'atlas-download-batch': '/api/extension/files',
  'atlas-check-batch': '/api/extension/files/check',
  'atlas-react': '/api/extension/files/react',
  'atlas-delete-download': '/api/extension/files/delete-download',
  'atlas-open-tabs-request': 'internal://open-tabs',
};

function resolveRequestMeta(message: unknown): { messageType: string; path: string } {
  if (!message || typeof message !== 'object') {
    return {
      messageType: 'unknown',
      path: 'message://unknown',
    };
  }

  const typeValue = (message as { type?: unknown }).type;
  const messageType = typeof typeValue === 'string' && typeValue.trim() !== '' ? typeValue.trim() : 'unknown';
  return {
    messageType,
    path: REQUEST_PATHS[messageType] ?? `message://${messageType}`,
  };
}

export function createSendMessageSafe(
  sendMessage: SendMessage,
  showToast: ShowToast,
  requestTracker?: RequestTracker
) {
  let requestId = 0;

  return (message: unknown, callback: (response: unknown) => void) => {
    const meta = resolveRequestMeta(message);
    requestId += 1;
    const requestEntry: AtlasRequestTraceEntry = {
      id: requestId,
      messageType: meta.messageType,
      path: meta.path,
      state: 'executing',
      startedAt: Date.now(),
      finishedAt: null,
    };
    requestTracker?.onStart?.(requestEntry);

    let finished = false;
    const finishRequest = (state: AtlasRequestState) => {
      if (finished) {
        return;
      }

      finished = true;
      requestTracker?.onFinish?.({
        ...requestEntry,
        state,
        finishedAt: Date.now(),
      });
    };

    try {
      sendMessage(message, (response) => {
        if (!response) {
          finishRequest('failed');
          callback(response);
          return;
        }

        if (typeof response === 'object' && response !== null && 'ok' in response) {
          const ok = Boolean((response as { ok?: unknown }).ok);
          finishRequest(ok ? 'completed' : 'failed');
          callback(response);
          return;
        }

        finishRequest('completed');
        callback(response);
      });
    } catch (error) {
      const messageText = (() => {
        if (error instanceof Error) {
          return error.message;
        }

        if (error && typeof error === 'object' && 'message' in error) {
          const messageValue = (error as { message?: unknown }).message;
          return typeof messageValue === 'string' ? messageValue : String(messageValue);
        }

        return String(error);
      })();

      if (messageText.includes('Extension context invalidated')) {
        showToast('Atlas extension was reloaded. Refresh this tab.', 'danger');
      } else {
        showToast('Atlas extension error. Refresh this tab.', 'danger');
      }

      finishRequest('failed');
      callback(null);
    }
  };
}
