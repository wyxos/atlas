type SendMessage = (message: unknown, callback: (response: unknown) => void) => void;
type ShowToast = (message: string, tone?: 'info' | 'danger') => void;

export function createSendMessageSafe(sendMessage: SendMessage, showToast: ShowToast) {
  return (message: unknown, callback: (response: unknown) => void) => {
    try {
      sendMessage(message, callback);
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

      callback(null);
    }
  };
}
