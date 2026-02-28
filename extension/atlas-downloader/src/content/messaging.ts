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
  durationMs: number | null;
  payload: unknown;
  response: unknown;
  errorMessage: string | null;
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

function toTraceSnapshot(value: unknown): unknown {
  const seen = new WeakSet<object>();
  const replacer = (_key: string, current: unknown): unknown => {
    if (current === undefined) {
      return '[undefined]';
    }

    if (typeof current === 'bigint') {
      return current.toString();
    }

    if (typeof current === 'function') {
      return '[function]';
    }

    if (typeof current === 'symbol') {
      return String(current);
    }

    if (current && typeof current === 'object') {
      if (seen.has(current as object)) {
        return '[circular]';
      }

      seen.add(current as object);
    }

    return current;
  };

  try {
    const serialized = JSON.stringify(value, replacer);
    if (serialized === undefined) {
      return value === undefined ? '[undefined]' : String(value);
    }

    return JSON.parse(serialized) as unknown;
  } catch {
    if (value instanceof Error) {
      return {
        name: value.name,
        message: value.message,
        stack: value.stack ?? null,
      };
    }

    return String(value);
  }
}

export function createSendMessageSafe(
  sendMessage: SendMessage,
  showToast: ShowToast,
  requestTracker?: RequestTracker
) {
  let requestId = 0;

  return (message: unknown, callback: (response: unknown) => void) => {
    const meta = resolveRequestMeta(message);
    const startedAt = Date.now();
    requestId += 1;
    const requestEntry: AtlasRequestTraceEntry = {
      id: requestId,
      messageType: meta.messageType,
      path: meta.path,
      state: 'executing',
      startedAt,
      finishedAt: null,
      durationMs: null,
      payload: toTraceSnapshot(message),
      response: null,
      errorMessage: null,
    };
    requestTracker?.onStart?.(requestEntry);

    let finished = false;
    const finishRequest = (
      state: AtlasRequestState,
      response: unknown = null,
      errorMessage: string | null = null
    ) => {
      if (finished) {
        return;
      }

      finished = true;
      const finishedAt = Date.now();
      requestTracker?.onFinish?.({
        ...requestEntry,
        state,
        finishedAt,
        durationMs: Math.max(0, finishedAt - startedAt),
        response: toTraceSnapshot(response),
        errorMessage,
      });
    };

    try {
      sendMessage(message, (response) => {
        if (!response) {
          finishRequest('failed', response, 'Empty extension response');
          callback(response);
          return;
        }

        if (typeof response === 'object' && response !== null && 'ok' in response) {
          const ok = Boolean((response as { ok?: unknown }).ok);
          finishRequest(ok ? 'completed' : 'failed', response, ok ? null : 'Request returned ok=false');
          callback(response);
          return;
        }

        finishRequest('completed', response);
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

      finishRequest('failed', null, messageText);
      callback(null);
    }
  };
}
