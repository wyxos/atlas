import { beforeEach, describe, expect, it, vi } from 'vitest';

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('installCivitAiModelBrowseCtas', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/') as unknown as Location,
        });
        vi.stubGlobal('MutationObserver', class {
            disconnect(): void {}
            observe(): void {}
        });

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: vi.fn((_: unknown, callback?: (response: unknown) => void) => {
                    callback?.({ ok: true });
                }),
            },
        });
    });

    it('adds an Open in Atlas CTA beside Civitai model urn rows and forwards the ids', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/models/960593/example') as unknown as Location,
        });
        document.body.innerHTML = `
            <table>
                <tbody>
                    <tr>
                        <td>AIR</td>
                        <td>
                            <div>
                                <div>
                                    <code>civitai:</code>
                                    <code>960593</code>
                                    <code>@</code>
                                    <code>1804885</code>
                                </div>
                                <button type="button">Copy</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        `;

        const { installCivitAiModelBrowseCtas } = await import('./civitai-model-browse-cta');

        installCivitAiModelBrowseCtas();

        const button = Array.from(document.querySelectorAll('button'))
            .find((candidate) => candidate.textContent === 'Open in Atlas');

        expect(button).toBeTruthy();

        button?.click();
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_CIVITAI_MODEL_TAB',
            modelId: 960593,
            modelVersionId: 1804885,
        }, expect.any(Function));
    });

    it('supports rows that only expose a model id', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/models/178169/example') as unknown as Location,
        });
        document.body.innerHTML = `
            <table>
                <tbody>
                    <tr>
                        <td>AIR</td>
                        <td>
                            <div>
                                <div>
                                    <code>civitai:</code>
                                    <code>178169</code>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        `;

        const { installCivitAiModelBrowseCtas } = await import('./civitai-model-browse-cta');

        installCivitAiModelBrowseCtas();

        const button = Array.from(document.querySelectorAll('button'))
            .find((candidate) => candidate.textContent === 'Open in Atlas');

        expect(button).toBeTruthy();

        button?.click();
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_CIVITAI_MODEL_TAB',
            modelId: 178169,
            modelVersionId: null,
        }, expect.any(Function));
    });

    it('does nothing outside Civitai pages', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://example.com/models/960593') as unknown as Location,
        });
        document.body.innerHTML = `
            <div>
                <code>civitai:</code>
                <code>960593</code>
                <code>@</code>
                <code>1804885</code>
            </div>
        `;

        const { installCivitAiModelBrowseCtas } = await import('./civitai-model-browse-cta');

        installCivitAiModelBrowseCtas();

        expect(Array.from(document.querySelectorAll('button'))
            .some((candidate) => candidate.textContent === 'Open in Atlas')).toBe(false);
    });
});
