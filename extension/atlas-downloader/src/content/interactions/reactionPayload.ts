import { buildItemFromElement } from '../items';
import type { InteractionDependencies } from './shared';

type AtlasReactionPayload = {
  type: string;
  url: string;
  referrer_url: string;
  page_title: string;
  tag_name: string;
  width: number | null;
  height: number | null;
  alt: string;
  preview_url: string;
  source: string;
  download_via?: 'yt-dlp';
  force_download?: boolean;
};

type MediaItem = NonNullable<ReturnType<typeof buildItemFromElement>>;

function resolveSourceLookupUrl(url: string, referrerUrl: string | null | undefined): string {
  return (referrerUrl || '').trim() || window.location.href || url;
}

function buildReactionPayloadFromItem(
  item: MediaItem,
  reactionType: string,
  deps: InteractionDependencies
): AtlasReactionPayload {
  return {
    type: reactionType,
    url: item.url,
    referrer_url: item.referrer_url || window.location.href,
    page_title: deps.limitString(document.title, deps.maxMetadataLen),
    tag_name: item.tag_name,
    width: item.width,
    height: item.height,
    alt: deps.limitString(item.alt || '', deps.maxMetadataLen),
    preview_url: item.preview_url || '',
    source: deps.sourceFromMediaUrl(resolveSourceLookupUrl(item.url, item.referrer_url)),
  };
}

function isBlobOrDataUrl(url: string): boolean {
  const value = (url || '').trim().toLowerCase();
  return value.startsWith('blob:') || value.startsWith('data:');
}

export function buildReactionPayloadFromMedia(
  media: Element,
  reactionType: string,
  deps: InteractionDependencies
): AtlasReactionPayload | null {
  const item = buildItemFromElement(media, deps.minWidth);
  if (item) {
    return buildReactionPayloadFromItem(item, reactionType, deps);
  }

  if (!(media instanceof HTMLVideoElement)) {
    return null;
  }

  const width = media.videoWidth || media.clientWidth || null;
  if (width && width < deps.minWidth) {
    return null;
  }

  const rawSrc = media.currentSrc || media.src || '';
  if (!isBlobOrDataUrl(rawSrc)) {
    return null;
  }

  const pageUrl = window.location.href;
  return {
    type: reactionType,
    url: pageUrl,
    referrer_url: pageUrl,
    page_title: deps.limitString(document.title, deps.maxMetadataLen),
    tag_name: 'video',
    width,
    height: media.videoHeight || media.clientHeight || null,
    alt: '',
    preview_url: media.poster || '',
    source: deps.sourceFromMediaUrl(pageUrl),
    download_via: 'yt-dlp',
  };
}
