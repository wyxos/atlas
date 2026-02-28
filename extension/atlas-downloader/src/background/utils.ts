export function resolveMessageType(message: unknown): string | null {
  if (!message || typeof message !== 'object') {
    return null;
  }

  const type = (message as { type?: unknown }).type;
  return typeof type === 'string' ? type : null;
}

export function normalizeBaseUrl(input: string): string {
  const trimmed = input.trim();
  if (!trimmed) {
    return '';
  }

  const withScheme = /^https?:\/\//i.test(trimmed)
    ? trimmed
    : `https://${trimmed}`;

  return withScheme.replace(/\/+$/, '');
}

export function networkErrorMessage(error: unknown): string {
  if (error instanceof DOMException && error.name === 'AbortError') {
    return 'Atlas request timed out. Please try again.';
  }

  if (error instanceof Error && error.message) {
    return `Atlas request failed: ${error.message}`;
  }

  return 'Atlas request failed. Please try again.';
}
