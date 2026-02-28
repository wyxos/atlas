import { describe, expect, it, vi } from 'vitest';
import { createSendMessageSafe } from './messaging';

describe('createSendMessageSafe', () => {
  it('tracks payload, response, and duration for successful requests', () => {
    const onStart = vi.fn();
    const onFinish = vi.fn();

    const sendMessage = vi.fn((message: unknown, callback: (response: unknown) => void) => {
      callback({
        ok: true,
        data: {
          echoed: message,
        },
      });
    });

    const sendMessageSafe = createSendMessageSafe(sendMessage, vi.fn(), {
      onStart,
      onFinish,
    });

    sendMessageSafe(
      {
        type: 'atlas-react',
        payload: {
          file_id: 101,
        },
      },
      () => undefined
    );

    const started = onStart.mock.calls[0][0];
    const finished = onFinish.mock.calls[0][0];

    expect(started.payload).toEqual({
      type: 'atlas-react',
      payload: {
        file_id: 101,
      },
    });
    expect(finished.state).toBe('completed');
    expect(finished.response).toEqual({
      ok: true,
      data: {
        echoed: {
          type: 'atlas-react',
          payload: {
            file_id: 101,
          },
        },
      },
    });
    expect(typeof finished.durationMs).toBe('number');
  });

  it('tracks failed requests when response is empty', () => {
    const onFinish = vi.fn();

    const sendMessageSafe = createSendMessageSafe(
      (_message, callback) => {
        callback(null);
      },
      vi.fn(),
      {
        onFinish,
      }
    );

    sendMessageSafe({ type: 'atlas-download' }, () => undefined);

    const finished = onFinish.mock.calls[0][0];
    expect(finished.state).toBe('failed');
    expect(finished.errorMessage).toBe('Empty extension response');
  });
});