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
    it('classifies post and image pages on both civitai hostnames', () => {
        expect(classifyCivitAiReactionPage('https://civitai.com/posts/9202002')).toBe('post-page');
        expect(classifyCivitAiReactionPage('https://civitai.red/images/9101002')).toBe('image-page');
        expect(classifyCivitAiReactionPage('https://example.com/images/9101002')).toBeNull();
    });
});

describe('canonicalizeCivitAiBadgeCheckUrl', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('canonicalizes civitai image variants to the stable original url while browsing civitai.red', () => {
        setWindowLocation('https://civitai.red/images/9101001');
        document.body.innerHTML = `
            <a href="/images/9101001">
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
        setWindowLocation('https://civitai.com/posts/9202002');
        document.body.innerHTML = `
            <a href="/images/9101002">
                <video id="video" src="https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/4MGM4VAVYH67B8TYY43NVH1780.mp4"></video>
            </a>
        `;

        const video = document.getElementById('video');
        if (!(video instanceof HTMLVideoElement)) {
            throw new Error('Expected video element.');
        }

        expect(canonicalizeCivitAiBadgeCheckUrl(video.src, video)).toBe(
            'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/0d6b721b-6256-4849-99ba-05c7cbb5b64f/transcode=true,original=true,quality=90/9101002.mp4',
        );
    });
});

describe('collectCivitAiListingMetadataOverrides', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        setWindowLocation('https://civitai.red/images/9101003');
    });

    it('opens the image page menu when needed and extracts post, user, and resource containers', async () => {
        const card = document.createElement('div');
        card.className = 'mantine-Paper-root';
        card.innerHTML = `
            <button type="button" aria-haspopup="menu" aria-expanded="false" id="image-menu"></button>
            <a href="/images/9101003">
                <img src="https://image.civitai.com/token/guid/width=800,original=false/example.jpeg" alt="image">
            </a>
        `;
        document.body.appendChild(card);

        const creatorCard = document.createElement('div');
        creatorCard.className = 'CreatorCard_profileDetailsContainer__8QORX';
        creatorCard.innerHTML = `
            <a href="https://civitai.red/user/exampleCreator">
                <p>exampleCreator</p>
            </a>
        `;
        document.body.appendChild(creatorCard);

        const models = document.createElement('ul');
        models.innerHTML = `
            <li>
                <a href="https://civitai.red/models/9303001/example-checkpoint?modelVersionId=9404001"><p>Example Checkpoint</p></a>
                <div><span>Checkpoint</span></div>
            </li>
            <li>
                <a href="https://civitai.red/models/9303002/example-lora?modelVersionId=9404002"><p>Example LoRA</p></a>
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
            postLink.href = '/posts/9202001';
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
            postId: 9202001,
            username: 'exampleCreator',
            resource_containers: [
                {
                    type: 'Checkpoint',
                    modelId: 9303001,
                    modelVersionId: 9404001,
                    referrerUrl: 'https://civitai.red/models/9303001/example-checkpoint?modelVersionId=9404001',
                },
                {
                    type: 'LoRA',
                    modelId: 9303002,
                    modelVersionId: 9404002,
                    referrerUrl: 'https://civitai.red/models/9303002/example-lora?modelVersionId=9404002',
                },
            ],
        });
    });

    it('does not open the menu when a post link is already present', async () => {
        const card = document.createElement('div');
        card.className = 'mantine-Paper-root';
        card.innerHTML = `
            <button type="button" aria-haspopup="menu" aria-expanded="false" id="image-menu"></button>
            <a href="/images/9101003">
                <img src="https://image.civitai.com/token/guid/width=800,original=false/example.jpeg" alt="image">
            </a>
        `;
        document.body.appendChild(card);

        const creatorCard = document.createElement('div');
        creatorCard.className = 'CreatorCard_profileDetailsContainer__8QORX';
        creatorCard.innerHTML = `
            <a href="/user/exampleCreator">
                <p>exampleCreator</p>
            </a>
        `;
        document.body.appendChild(creatorCard);

        const postLink = document.createElement('a');
        postLink.href = '/posts/9202001';
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
            postId: 9202001,
            username: 'exampleCreator',
        });
    });
});
