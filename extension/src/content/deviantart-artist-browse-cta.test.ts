import { beforeEach, describe, expect, it, vi } from 'vitest';

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('installDeviantArtArtistBrowseCtas', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        document.body.innerHTML = '';
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://www.deviantart.com/') as unknown as Location,
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

    it('adds an Atlas CTA beside the DeviantArt profile header username', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://www.deviantart.com/velvetemberartist') as unknown as Location,
        });
        document.body.innerHTML = `
            <h1 class="xFFJW8">
                <a class="user-link PX4oKN NYgYcG" href="https://www.deviantart.com/velvetemberartist">
                    VelvetEmberArtist
                </a>
            </h1>
        `;

        const { installDeviantArtArtistBrowseCtas } = await import('./deviantart-artist-browse-cta');

        installDeviantArtArtistBrowseCtas();

        const button = document.querySelector('[data-atlas-deviantart-artist-browse-cta]');

        expect(button).toBeInstanceOf(HTMLButtonElement);
        expect(button?.textContent).toBe('Open in Atlas');
        expect(button?.previousElementSibling?.textContent?.trim()).toBe('VelvetEmberArtist');

        button?.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        }));
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_DEVIANTART_USERNAME_TAB',
            username: 'velvetemberartist',
            sourceHostname: 'www.deviantart.com',
            sourceUrl: 'https://www.deviantart.com/velvetemberartist',
        }, expect.any(Function));
    });

    it('adds the CTA beside the deviation byline username without targeting More by links', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://www.deviantart.com/velvetemberartist/art/Exclusive-Artwork-Adoptable-1339260729') as unknown as Location,
        });
        document.body.innerHTML = `
            <aside>
                <a class="user-link PX4oKN wl3JTQ" href="https://www.deviantart.com/velvetemberartist/gallery?deviationid=1339260729#content">
                    More by VelvetEmberArtist
                </a>
            </aside>
            <section>
                <div class="g4q3kb">
                    <span>By</span>
                    <div class="TzdmPT kmjYyN">
                        <a class="user-link PX4oKN" href="https://www.deviantart.com/velvetemberartist/gallery">
                            VelvetEmberArtist
                        </a>
                    </div>
                    <button type="button">Watch</button>
                </div>
            </section>
        `;

        const { installDeviantArtArtistBrowseCtas } = await import('./deviantart-artist-browse-cta');

        installDeviantArtArtistBrowseCtas();

        const buttons = Array.from(document.querySelectorAll('[data-atlas-deviantart-artist-browse-cta]'));

        expect(buttons).toHaveLength(1);
        expect(buttons[0]?.previousElementSibling?.textContent?.trim()).toBe('VelvetEmberArtist');

        buttons[0]?.dispatchEvent(new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        }));
        await flushPromises();

        expect(chrome.runtime.sendMessage).toHaveBeenCalledWith({
            type: 'ATLAS_OPEN_DEVIANTART_USERNAME_TAB',
            username: 'velvetemberartist',
            sourceHostname: 'www.deviantart.com',
            sourceUrl: 'https://www.deviantart.com/velvetemberartist/art/Exclusive-Artwork-Adoptable-1339260729',
        }, expect.any(Function));
    });

    it('does nothing outside DeviantArt pages', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://example.com/velvetemberartist') as unknown as Location,
        });
        document.body.innerHTML = `
            <h1>
                <a href="https://www.deviantart.com/velvetemberartist">VelvetEmberArtist</a>
            </h1>
        `;

        const { installDeviantArtArtistBrowseCtas } = await import('./deviantart-artist-browse-cta');

        installDeviantArtArtistBrowseCtas();

        expect(document.querySelector('[data-atlas-deviantart-artist-browse-cta]')).toBeNull();
    });
});
