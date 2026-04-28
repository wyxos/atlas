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
            value: new URL('https://civitai.com/models/9303101/example') as unknown as Location,
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
                                    <code>9303101</code>
                                    <code>@</code>
                                    <code>9404101</code>
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
            modelId: 9303101,
            modelVersionId: 9404101,
        }, expect.any(Function));
    });

    it('uses the current Civitai model version when the page switches versions without recreating the CTA', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/models/9303102?modelVersionId=9404102') as unknown as Location,
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
                                    <code>9303102</code>
                                    <code>@</code>
                                    <code>9404102</code>
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

        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/models/9303102?modelVersionId=9404103') as unknown as Location,
        });

        button?.click();
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_CIVITAI_MODEL_TAB',
            modelId: 9303102,
            modelVersionId: 9404103,
        }, expect.any(Function));
    });

    it('supports rows that only expose a model id on civitai.red', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.red/models/9303103/example') as unknown as Location,
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
                                    <code>9303103</code>
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
            modelId: 9303103,
            modelVersionId: null,
        }, expect.any(Function));
    });

    it('renders the CTA for the current Mantine URN table row structure', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/models/9303104/example') as unknown as Location,
        });
        document.body.innerHTML = `
            <tr class="m_4e7aa4fd mantine-Table-tr" data-with-row-border="true">
                <td
                    style="width:30%;padding:7px 7px !important"
                    class="m_4e7aa4ef mantine-Table-td bg-gray-0 dark:bg-dark-6"
                    data-with-column-border="true"
                >
                    <div
                        style="--group-gap:var(--mantine-spacing-xs);--group-align:center;--group-justify:flex-start;--group-wrap:wrap"
                        class="m_4081bf90 mantine-Group-root"
                    >
                        <p style="font-weight:500" class="mantine-focus-auto m_b6d8b162 mantine-Text-root">AIR</p>
                    </div>
                </td>
                <td style="padding:7px 7px !important" class="m_4e7aa4ef mantine-Table-td" data-with-column-border="true">
                    <div
                        style="--group-gap:calc(0.25rem * var(--mantine-scale));--group-align:center;--group-justify:flex-start;--group-wrap:wrap"
                        class="m_4081bf90 mantine-Group-root"
                    >
                        <div
                            style="--group-gap:0rem;--group-align:center;--group-justify:flex-start;--group-wrap:wrap"
                            class="m_4081bf90 mantine-Group-root"
                        >
                            <code class="ModelURN_code__eKiIQ m_b183c0a2 mantine-Code-root" dir="ltr">civitai:</code>
                            <code
                                style="--code-bg:var(--mantine-color-blue-filled)"
                                class="ModelURN_code__eKiIQ m_b183c0a2 mantine-Code-root"
                                dir="ltr"
                            >9303104</code>
                            <code class="ModelURN_code__eKiIQ m_b183c0a2 mantine-Code-root" dir="ltr">@</code>
                            <code
                                style="--code-bg:var(--mantine-color-blue-filled)"
                                class="ModelURN_code__eKiIQ m_b183c0a2 mantine-Code-root"
                                dir="ltr"
                            >9404104</code>
                        </div>
                        <button
                            class="mantine-focus-auto mantine-active m_8d3f4000 mantine-ActionIcon-root m_87cf2631 mantine-UnstyledButton-root"
                            data-variant="subtle"
                            data-size="xs"
                            type="button"
                        >
                            <span class="m_8d3afb97 mantine-ActionIcon-icon">Copy</span>
                        </button>
                    </div>
                </td>
            </tr>
        `;

        const { installCivitAiModelBrowseCtas } = await import('./civitai-model-browse-cta');

        installCivitAiModelBrowseCtas();

        const allButtons = Array.from(document.querySelectorAll('button'));
        const openButton = allButtons.find((candidate) => candidate.textContent === 'Open in Atlas');
        const copyButton = allButtons.find((candidate) => candidate.textContent?.includes('Copy'));

        expect(openButton).toBeTruthy();
        expect(copyButton).toBeTruthy();
        expect(openButton?.nextElementSibling).toBe(copyButton ?? null);

        openButton?.click();
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_CIVITAI_MODEL_TAB',
            modelId: 9303104,
            modelVersionId: 9404104,
        }, expect.any(Function));
    });

    it('does nothing outside Civitai pages', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://example.com/models/9303101') as unknown as Location,
        });
        document.body.innerHTML = `
            <div>
                <code>civitai:</code>
                <code>9303101</code>
                <code>@</code>
                <code>9404101</code>
            </div>
        `;

        const { installCivitAiModelBrowseCtas } = await import('./civitai-model-browse-cta');

        installCivitAiModelBrowseCtas();

        expect(Array.from(document.querySelectorAll('button'))
            .some((candidate) => candidate.textContent === 'Open in Atlas')).toBe(false);
    });
});
