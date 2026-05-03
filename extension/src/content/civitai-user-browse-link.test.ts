import { beforeEach, describe, expect, it, vi } from 'vitest';

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('installCivitAiUserBrowseLinks', () => {
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

    it('makes the Civitai profile username clickable and forwards the username', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/user/forsunlee404') as unknown as Location,
        });
        document.body.innerHTML = `
            <div class="m_6d731127 mantine-Stack-root" style="--stack-gap: 0rem; --stack-align: stretch; --stack-justify: flex-start;">
                <div class="m_4081bf90 mantine-Group-root" style="--group-gap: calc(0.5rem * var(--mantine-scale)); --group-align: center; --group-justify: flex-start; --group-wrap: nowrap;">
                    <p
                        class="mantine-focus-auto align-middle drop-shadow-[1px_1px_1px_rgba(0,0,0,0.8)] dark:drop-shadow-[1px_1px_1px_rgba(0,0,0,0.2)] m_b6d8b162 mantine-Text-root"
                        data-size="xl"
                        data-line-clamp="true"
                        style="--text-fz: var(--mantine-font-size-xl); --text-lh: var(--mantine-line-height-xl); --text-line-clamp: 1; font-weight: 500; text-decoration: none;"
                    >forsunlee404</p>
                </div>
                <p
                    class="mantine-focus-auto m_b6d8b162 mantine-Text-root"
                    data-size="sm"
                    style="--text-fz: var(--mantine-font-size-sm); --text-lh: var(--mantine-line-height-sm); color: var(--mantine-color-dimmed);"
                >Joined Dec 7, 2024</p>
            </div>
        `;

        const { installCivitAiUserBrowseLinks } = await import('./civitai-user-browse-link');

        installCivitAiUserBrowseLinks();

        const username = document.querySelector('[data-atlas-civitai-user-browse-link]');

        expect(username).toBeInstanceOf(HTMLAnchorElement);
        expect(username?.textContent).toBe('forsunlee404');
        expect(username?.getAttribute('href')).toBe('#');

        username?.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        }));
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_CIVITAI_USERNAME_TAB',
            username: 'forsunlee404',
            sourceHostname: 'civitai.com',
            sourceUrl: 'https://civitai.com/user/forsunlee404',
        }, expect.any(Function));
    });

    it('marks Civitai red profile opens as nsfw', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.red/user/forsunlee404') as unknown as Location,
        });
        document.body.innerHTML = `
            <div class="mantine-Stack-root">
                <p class="mantine-Text-root" data-size="xl">forsunlee404</p>
                <p class="mantine-Text-root">Joined Dec 7, 2024</p>
            </div>
        `;

        const { installCivitAiUserBrowseLinks } = await import('./civitai-user-browse-link');

        installCivitAiUserBrowseLinks();

        const username = document.querySelector('[data-atlas-civitai-user-browse-link]');

        username?.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        }));
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_CIVITAI_USERNAME_TAB',
            username: 'forsunlee404',
            sourceHostname: 'civitai.red',
            sourceUrl: 'https://civitai.red/user/forsunlee404',
            nsfw: true,
        }, expect.any(Function));
    });

    it('attaches a fresh click listener when a previous content script left the link styling behind', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/user/forsunlee404') as unknown as Location,
        });
        document.body.innerHTML = `
            <div class="mantine-Stack-root">
                <p
                    class="mantine-Text-root"
                    data-size="xl"
                    data-atlas-civitai-user-browse-link="forsunlee404"
                    role="button"
                >forsunlee404</p>
                <p class="mantine-Text-root">Joined Dec 7, 2024</p>
            </div>
        `;

        const { installCivitAiUserBrowseLinks } = await import('./civitai-user-browse-link');

        installCivitAiUserBrowseLinks();

        const username = document.querySelector('[data-atlas-civitai-user-browse-link]');

        expect(username).toBeInstanceOf(HTMLAnchorElement);

        username?.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        }));
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_CIVITAI_USERNAME_TAB',
            username: 'forsunlee404',
            sourceHostname: 'civitai.com',
            sourceUrl: 'https://civitai.com/user/forsunlee404',
        }, expect.any(Function));
    });

    it('does not mark repeated plain username text outside the profile header', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/user/forsunlee404') as unknown as Location,
        });
        document.body.innerHTML = `
            <div class="mantine-Stack-root">
                <p class="mantine-Text-root" data-size="xl">forsunlee404</p>
                <p class="mantine-Text-root">Created by forsunlee404</p>
            </div>
        `;

        const { installCivitAiUserBrowseLinks } = await import('./civitai-user-browse-link');

        installCivitAiUserBrowseLinks();

        expect(document.querySelector('[data-atlas-civitai-user-browse-link]')).toBeNull();
    });

    it('does nothing outside Civitai user pages', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://civitai.com/models/9303101') as unknown as Location,
        });
        document.body.innerHTML = `
            <div class="mantine-Stack-root">
                <p class="mantine-Text-root" data-size="xl">forsunlee404</p>
                <p class="mantine-Text-root">Joined Dec 7, 2024</p>
            </div>
        `;

        const { installCivitAiUserBrowseLinks } = await import('./civitai-user-browse-link');

        installCivitAiUserBrowseLinks();

        expect(document.querySelector('[data-atlas-civitai-user-browse-link]')).toBeNull();
    });
});
