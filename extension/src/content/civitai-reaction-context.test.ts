import { beforeEach, describe, expect, it } from 'vitest';
import {
    canonicalizeCivitAiBadgeCheckUrl,
    classifyCivitAiReactionPage,
    collectCivitAiListingMetadataOverrides,
} from './civitai-reaction-context';

function setWindowLocation(url: string): void {
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: new URL(url) as unknown as Location,
    });
}

describe('classifyCivitAiReactionPage', () => {
    it('classifies post and image pages', () => {
        expect(classifyCivitAiReactionPage('https://civitai.com/posts/16973563')).toBe('post-page');
        expect(classifyCivitAiReactionPage('https://civitai.com/images/76477306')).toBe('image-page');
        expect(classifyCivitAiReactionPage('https://example.com/images/76477306')).toBeNull();
    });
});

describe('canonicalizeCivitAiBadgeCheckUrl', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('canonicalizes civitai image variants to the stable original url', () => {
        setWindowLocation('https://civitai.com/images/123066308');
        document.body.innerHTML = `
            <a href="/images/123066308">
                <img id="image" src="https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true,quality=90/f3a666a2-65dd-4738-a1f2-dd1de72f2636.jpeg" alt="image">
            </a>
        `;

        const image = document.getElementById('image');
        if (!(image instanceof HTMLImageElement)) {
            throw new Error('Expected image element.');
        }

        expect(canonicalizeCivitAiBadgeCheckUrl(image.src, image)).toBe(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true/8928e082-af52-4ade-a86e-d79e0ed63aa9.jpeg',
        );
    });

    it('uses the media image-page anchor to canonicalize civitai videos', () => {
        setWindowLocation('https://civitai.com/posts/16973563');
        document.body.innerHTML = `
            <a href="/images/76477306">
                <video id="video" src="https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4"></video>
            </a>
        `;

        const video = document.getElementById('video');
        if (!(video instanceof HTMLVideoElement)) {
            throw new Error('Expected video element.');
        }

        expect(canonicalizeCivitAiBadgeCheckUrl(video.src, video)).toBe(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/76477306.mp4',
        );
    });
});

describe('collectCivitAiListingMetadataOverrides', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        setWindowLocation('https://civitai.com/images/105372859');
    });

    it('opens the image page menu when needed and extracts post, user, and resource containers', async () => {
        const card = document.createElement('div');
        card.className = 'mantine-Paper-root';
        card.innerHTML = `
            <button type="button" aria-haspopup="menu" aria-expanded="false" id="image-menu"></button>
            <a href="/images/105372859">
                <img src="https://image.civitai.com/token/guid/width=800,original=false/example.jpeg" alt="image">
            </a>
        `;
        document.body.appendChild(card);

        const creatorCard = document.createElement('div');
        creatorCard.className = 'CreatorCard_profileDetailsContainer__8QORX';
        creatorCard.innerHTML = `
            <a href="/user/shepretends">
                <p>shepretends</p>
            </a>
        `;
        document.body.appendChild(creatorCard);

        const models = document.createElement('ul');
        models.innerHTML = `
            <li>
                <a href="/models/833294/noobai-xl-nai-xl?modelVersionId=1190596"><p>NoobAI-XL (NAI-XL)</p></a>
                <div><span>Checkpoint</span></div>
            </li>
            <li>
                <a href="/models/1368095/incase-style-noobai?modelVersionId=1545615"><p>Incase Style (NoobAI)</p></a>
                <div><span>LoRA</span></div>
            </li>
            <li>
                <a href="/models/123/no-version-model"><p>Skip Missing Version</p></a>
                <div><span>LoRA</span></div>
            </li>
        `;
        document.body.appendChild(models);

        const menuButton = card.querySelector('#image-menu');
        if (!(menuButton instanceof HTMLButtonElement)) {
            throw new Error('Expected menu button.');
        }

        let clickCount = 0;
        menuButton.addEventListener('click', () => {
            clickCount += 1;
            const existing = document.querySelector('[data-test-post-link]');
            if (existing) {
                existing.remove();
                menuButton.setAttribute('aria-expanded', 'false');
                return;
            }

            const postLink = document.createElement('a');
            postLink.href = '/posts/23377656';
            postLink.textContent = 'View Post';
            postLink.setAttribute('data-test-post-link', '1');
            document.body.appendChild(postLink);
            menuButton.setAttribute('aria-expanded', 'true');
        });

        const image = card.querySelector('img');
        if (!(image instanceof HTMLImageElement)) {
            throw new Error('Expected image.');
        }

        const overrides = await collectCivitAiListingMetadataOverrides(image);

        expect(clickCount).toBeGreaterThan(0);
        expect(overrides).toEqual({
            postId: 23377656,
            username: 'shepretends',
            resource_containers: [
                {
                    type: 'Checkpoint',
                    modelId: 833294,
                    modelVersionId: 1190596,
                    referrerUrl: 'https://civitai.com/models/833294/noobai-xl-nai-xl?modelVersionId=1190596',
                },
                {
                    type: 'LoRA',
                    modelId: 1368095,
                    modelVersionId: 1545615,
                    referrerUrl: 'https://civitai.com/models/1368095/incase-style-noobai?modelVersionId=1545615',
                },
            ],
        });
    });

    it('does not open the menu when a post link is already present', async () => {
        const card = document.createElement('div');
        card.className = 'mantine-Paper-root';
        card.innerHTML = `
            <button type="button" aria-haspopup="menu" aria-expanded="false" id="image-menu"></button>
            <a href="/images/105372859">
                <img src="https://image.civitai.com/token/guid/width=800,original=false/example.jpeg" alt="image">
            </a>
        `;
        document.body.appendChild(card);

        const creatorCard = document.createElement('div');
        creatorCard.className = 'CreatorCard_profileDetailsContainer__8QORX';
        creatorCard.innerHTML = `
            <a href="/user/shepretends">
                <p>shepretends</p>
            </a>
        `;
        document.body.appendChild(creatorCard);

        const postLink = document.createElement('a');
        postLink.href = '/posts/23377656';
        postLink.textContent = 'View Post';
        document.body.appendChild(postLink);

        const menuButton = card.querySelector('#image-menu');
        if (!(menuButton instanceof HTMLButtonElement)) {
            throw new Error('Expected menu button.');
        }

        let clickCount = 0;
        menuButton.addEventListener('click', () => {
            clickCount += 1;
        });

        const image = card.querySelector('img');
        if (!(image instanceof HTMLImageElement)) {
            throw new Error('Expected image.');
        }

        const overrides = await collectCivitAiListingMetadataOverrides(image);

        expect(clickCount).toBe(0);
        expect(overrides).toMatchObject({
            postId: 23377656,
            username: 'shepretends',
        });
    });
});
