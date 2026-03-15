import { beforeEach, describe, expect, it } from 'vitest';
import {
    classifyCivitAiReactionPage,
    collectCivitAiBatchReactionItems,
    collectCivitAiListingMetadataOverrides,
    rewriteCivitAiImageAssetUrl,
} from './civitai-reaction-context';

function setWindowLocation(url: string): void {
    Object.defineProperty(window, 'location', {
        configurable: true,
        value: new URL(url) as unknown as Location,
    });
}

function createPostCard(innerHtml: string): HTMLDivElement {
    const card = document.createElement('div');
    card.className = 'mantine-Paper-root';
    card.innerHTML = innerHtml;

    return card;
}

describe('classifyCivitAiReactionPage', () => {
    it('classifies post and image pages', () => {
        expect(classifyCivitAiReactionPage('https://civitai.com/posts/16973563')).toBe('post-page');
        expect(classifyCivitAiReactionPage('https://civitai.com/images/76477306')).toBe('image-page');
        expect(classifyCivitAiReactionPage('https://example.com/images/76477306')).toBeNull();
    });
});

describe('rewriteCivitAiImageAssetUrl', () => {
    it('rewrites preview image urls to original quality asset urls', () => {
        expect(rewriteCivitAiImageAssetUrl(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/width=800,original=false/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg',
        )).toBe(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/cf304569-f64a-4f90-aa1b-16bfb641f143/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg',
        );
    });
});

describe('collectCivitAiBatchReactionItems', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        setWindowLocation('https://civitai.com/posts/16973563');
    });

    it('collects mixed post media, prefers mp4, rewrites image urls, and keeps the clicked item first', async () => {
        const root = document.createElement('div');

        root.appendChild(createPostCard(`
            <a href="/images/76477306">
                <video>
                    <source src="https://image.civitai.com/token-a/guid-a/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.webm" type="video/webm">
                    <source src="https://image.civitai.com/token-a/guid-a/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4" type="video/mp4">
                </video>
            </a>
        `));
        root.appendChild(createPostCard(`
            <a href="/images/76490204">
                <img src="https://image.civitai.com/token-b/guid-b/width=800,original=false/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg" alt="preview">
            </a>
        `));

        const ad = document.createElement('div');
        ad.innerHTML = '<a href="/pricing"><img src="/ads/banner.jpg" alt="ad"></a>';
        root.appendChild(ad);

        root.appendChild(createPostCard(`
            <a href="/images/76490806">
                <video>
                    <source src="https://image.civitai.com/token-c/guid-c/transcode=true,original=true,quality=90/aca09ecc-f387-4173-979d-3d3b4f63275a.webm" type="video/webm">
                </video>
            </a>
        `));

        document.body.appendChild(root);

        const clickedImage = root.querySelector('img');
        if (!(clickedImage instanceof HTMLImageElement)) {
            throw new Error('Expected clicked image.');
        }

        const items = await collectCivitAiBatchReactionItems(clickedImage);

        expect(items).toEqual([
            {
                candidateId: 'image-76490204',
                url: 'https://image.civitai.com/token-b/guid-b/original=true,quality=90/1341e338-9de2-4d1a-a6ff-6c86fb7ad759.jpeg',
                referrerUrlHashAware: 'https://civitai.com/images/76490204',
                pageUrl: 'https://civitai.com/posts/16973563',
                tagName: 'img',
            },
            {
                candidateId: 'image-76477306',
                url: 'https://image.civitai.com/token-a/guid-a/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4',
                referrerUrlHashAware: 'https://civitai.com/images/76477306',
                pageUrl: 'https://civitai.com/posts/16973563',
                tagName: 'video',
            },
            {
                candidateId: 'image-76490806',
                url: 'https://image.civitai.com/token-c/guid-c/transcode=true,original=true,quality=90/aca09ecc-f387-4173-979d-3d3b4f63275a.webm',
                referrerUrlHashAware: 'https://civitai.com/images/76490806',
                pageUrl: 'https://civitai.com/posts/16973563',
                tagName: 'video',
            },
        ]);
    });
});

describe('collectCivitAiListingMetadataOverrides', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        setWindowLocation('https://civitai.com/images/105372859');
    });

    it('opens the image page menu when needed and extracts post, user, and resource containers', async () => {
        const card = createPostCard(`
            <button type="button" aria-haspopup="menu" aria-expanded="false" id="image-menu"></button>
            <a href="/images/105372859">
                <img src="https://image.civitai.com/token/guid/width=800,original=false/example.jpeg" alt="image">
            </a>
        `);
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
        const card = createPostCard(`
            <button type="button" aria-haspopup="menu" aria-expanded="false" id="image-menu"></button>
            <a href="/images/105372859">
                <img src="https://image.civitai.com/token/guid/width=800,original=false/example.jpeg" alt="image">
            </a>
        `);
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
