import type { MediaCleanerConfig } from '../site-customizations';
import type { MediaElement } from './media-utils';
import { canonicalizeCivitAiMediaUrl } from './civitai-reaction-context';
import { cleanupUrlQueryParams } from '../referrer-cleanup';

type MediaCleanerContext = {
    media?: MediaElement | null;
    candidatePageUrls?: Array<string | null | undefined>;
};

export function applyMediaCleaner(
    mediaUrl: string | null | undefined,
    mediaCleaner: MediaCleanerConfig,
    context: MediaCleanerContext = {},
): string | null {
    if (typeof mediaUrl !== 'string') {
        return null;
    }

    let cleaned = mediaUrl.trim();
    if (cleaned === '') {
        return null;
    }

    for (const strategy of mediaCleaner.strategies) {
        if (strategy === 'civitaiCanonical') {
            cleaned = canonicalizeCivitAiMediaUrl(cleaned, {
                media: context.media ?? null,
                candidatePageUrls: context.candidatePageUrls ?? [],
            }) ?? cleaned;
        }
    }

    const queryCleaned = cleanupUrlQueryParams(cleaned, mediaCleaner.stripQueryParams);
    if (queryCleaned === null) {
        return null;
    }

    cleaned = queryCleaned;

    for (const rewriteRule of mediaCleaner.rewriteRules) {
        try {
            const regex = new RegExp(rewriteRule.pattern, 'i');
            if (!regex.test(cleaned)) {
                continue;
            }

            cleaned = cleaned.replace(regex, rewriteRule.replace);
            break;
        } catch {
            continue;
        }
    }

    return cleaned;
}
