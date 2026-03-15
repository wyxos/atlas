import { beforeEach, describe, expect, it } from 'vitest';
import {
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
