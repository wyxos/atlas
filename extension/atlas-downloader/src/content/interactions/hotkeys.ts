import { buildItemFromElement } from '../items';
import {
  isOwnUiElement,
  parseFileStatusMeta,
  resolveMediaAtPoint,
  type HotkeysOptions,
  type InteractionDependencies,
} from './shared';

export function installHotkeys(options: HotkeysOptions, deps: InteractionDependencies) {
  const enabled = options.enabled ?? true;
  const getHintShown = options.getHintShown ?? (() => false);
  const setHintShown = options.setHintShown ?? (() => {});

  const maybeHint = () => {
    if (getHintShown()) return;
    setHintShown(true);
    options.showToast('Hotkeys: Alt+Left=Like, Alt+Middle=Love, Alt+Right=Dislike');
  };

  const emitShortcutReactionState = (
    media: Element,
    pending: boolean,
    reactionType: string | null,
    url: string | null = null
  ) => {
    window.dispatchEvent(
      new CustomEvent('atlas-shortcut-reaction-state', {
        detail: {
          media,
          pending,
          reactionType,
          url,
        },
      })
    );
  };

  const isOwnUiEvent = (event: MouseEvent) => {
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    return path.some((p) => p instanceof HTMLElement && p.id === deps.rootId);
  };
  const resolveEventMedia = (event: MouseEvent): Element | null => {
    const target = event.target instanceof Element ? event.target : null;
    const byTarget = target?.closest?.('img, video') ?? null;
    if (byTarget instanceof Element && !isOwnUiElement(byTarget, deps.rootId)) {
      return byTarget;
    }

    const byPoint = resolveMediaAtPoint(event.clientX, event.clientY, deps.rootId);
    if (byPoint) {
      return byPoint;
    }

    return null;
  };
  const resolveSourceLookupUrl = (url: string, referrerUrl: string | null | undefined): string =>
    (referrerUrl || '').trim() || window.location.href || url;

  // Some sites (especially video players) trigger actions on click/pointerup even if mousedown is prevented.
  // Swallow the click when we're handling a hotkey so play/pause/seek doesn't fire.
  document.addEventListener(
    'click',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
    },
    true
  );

  document.addEventListener(
    'mousedown',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      const reactionType = e.button === 0 ? 'like' : e.button === 1 ? 'love' : null;
      if (!reactionType) return;

      maybeHint();

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const item = buildItemFromElement(media, deps.minWidth);
      if (!item) {
        if (media instanceof HTMLVideoElement) {
          const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
          if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
            // Fallback: send the page URL and let Atlas resolve/download via yt-dlp.
            const pageUrl = window.location.href;
            const payload = {
              type: reactionType,
              url: pageUrl,
              referrer_url: pageUrl,
              page_title: deps.limitString(document.title, deps.maxMetadataLen),
              tag_name: 'video',
              width: media.videoWidth || media.clientWidth || null,
              height: media.videoHeight || media.clientHeight || null,
              alt: '',
              preview_url: media.poster || '',
              source: deps.sourceFromMediaUrl(pageUrl),
              download_via: 'yt-dlp',
            };

            deps.fetchAtlasStatus(options.sendMessageSafe, payload.url, payload.referrer_url || null, (status) => {
              if (status?.downloaded) {
                options
                  .chooseDialog({
                    title: 'Already downloaded',
                    message: 'Re-download before updating the reaction?',
                    confirmLabel: 'Re-download',
                    cancelLabel: 'Keep existing file',
                    alternateLabel: 'Cancel',
                  })
                  .then((choice) => {
                    if (choice === 'alternate') {
                      options.showToast('Cancelled.');
                      return;
                    }
                    emitShortcutReactionState(media, true, reactionType, payload.url);
                    if (choice === 'confirm') {
                      payload.force_download = true;
                    }

                    options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                      if (!response || !response.ok) {
                        emitShortcutReactionState(media, false, null, payload.url);
                        options.showToast(response?.error || 'Reaction failed.', 'danger');
                        return;
                      }

                      const data = response.data || null;
                      const file = data?.file || null;
                      const newReactionType = data?.reaction?.type
                        ? String(data.reaction.type)
                        : reactionType;
                      const fileMeta = parseFileStatusMeta(file);
                      deps.atlasStatusCache.set(payload.url, {
                        exists: Boolean(file),
                        downloaded: Boolean(file?.downloaded),
                        blacklisted: Boolean(file?.blacklisted_at),
                        reactionType: newReactionType,
                        downloadProgress: fileMeta.downloadProgress,
                        downloadedAt: fileMeta.downloadedAt,
                        ts: Date.now(),
                      });
                      emitShortcutReactionState(media, false, newReactionType, payload.url);

                      options.showToast(`Reacted (${reactionType}). Resolving video in Atlas…`);
                    });
                  });
                return;
              }
              emitShortcutReactionState(media, true, reactionType, payload.url);
              options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                if (!response || !response.ok) {
                  emitShortcutReactionState(media, false, null, payload.url);
                  options.showToast(response?.error || 'Reaction failed.', 'danger');
                  return;
                }

                const data = response.data || null;
                const file = data?.file || null;
                const newReactionType = data?.reaction?.type
                  ? String(data.reaction.type)
                  : reactionType;
                const fileMeta = parseFileStatusMeta(file);
                deps.atlasStatusCache.set(payload.url, {
                  exists: Boolean(file),
                  downloaded: Boolean(file?.downloaded),
                  blacklisted: Boolean(file?.blacklisted_at),
                  reactionType: newReactionType,
                  downloadProgress: fileMeta.downloadProgress,
                  downloadedAt: fileMeta.downloadedAt,
                  ts: Date.now(),
                });
                emitShortcutReactionState(media, false, newReactionType, payload.url);

                options.showToast(`Reacted (${reactionType}). Resolving video in Atlas…`);
              });
            });

            return;
          }

          options.showToast('No direct video URL found.');
          return;
        }

        options.showToast('No valid media URL found.');
        return;
      }

      const payload = {
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

      deps.fetchAtlasStatus(options.sendMessageSafe, payload.url, payload.referrer_url || null, (status) => {
        if (status?.downloaded) {
          options
            .chooseDialog({
              title: 'Already downloaded',
              message: 'Re-download before updating the reaction?',
              confirmLabel: 'Re-download',
              cancelLabel: 'Keep existing file',
              alternateLabel: 'Cancel',
            })
            .then((choice) => {
              if (choice === 'alternate') {
                options.showToast('Cancelled.');
                return;
              }
              emitShortcutReactionState(media, true, reactionType, payload.url);
              if (choice === 'confirm') {
                payload.force_download = true;
              }

              options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
                if (!response || !response.ok) {
                  emitShortcutReactionState(media, false, null, payload.url);
                  options.showToast(response?.error || 'Reaction failed.', 'danger');
                  return;
                }

                const data = response.data || null;
                const file = data?.file || null;
                const newReactionType = data?.reaction?.type ? String(data.reaction.type) : reactionType;
                const fileMeta = parseFileStatusMeta(file);
                deps.atlasStatusCache.set(payload.url, {
                  exists: Boolean(file),
                  downloaded: Boolean(file?.downloaded),
                  blacklisted: Boolean(file?.blacklisted_at),
                  reactionType: newReactionType,
                  downloadProgress: fileMeta.downloadProgress,
                  downloadedAt: fileMeta.downloadedAt,
                  ts: Date.now(),
                });
                emitShortcutReactionState(media, false, newReactionType, payload.url);

                options.showToast(`Reacted (${reactionType}). Queued download in Atlas.`);
              });
            });
          return;
        }
        emitShortcutReactionState(media, true, reactionType, payload.url);
        options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
          if (!response || !response.ok) {
            emitShortcutReactionState(media, false, null, payload.url);
            options.showToast(response?.error || 'Reaction failed.', 'danger');
            return;
          }

          const data = response.data || null;
          const file = data?.file || null;
          const newReactionType = data?.reaction?.type ? String(data.reaction.type) : reactionType;
          const fileMeta = parseFileStatusMeta(file);
          deps.atlasStatusCache.set(payload.url, {
            exists: Boolean(file),
            downloaded: Boolean(file?.downloaded),
            blacklisted: Boolean(file?.blacklisted_at),
            reactionType: newReactionType,
            downloadProgress: fileMeta.downloadProgress,
            downloadedAt: fileMeta.downloadedAt,
            ts: Date.now(),
          });
          emitShortcutReactionState(media, false, newReactionType, payload.url);

          options.showToast(`Reacted (${reactionType}). Queued download in Atlas.`);
        });
      });
    },
    true
  );

  document.addEventListener(
    'contextmenu',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      maybeHint();

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const item = buildItemFromElement(media, deps.minWidth);
      if (!item) {
        if (media instanceof HTMLVideoElement) {
          const rawSrc = (media.currentSrc || media.src || '').trim().toLowerCase();
          if (rawSrc.startsWith('blob:') || rawSrc.startsWith('data:')) {
            const pageUrl = window.location.href;
            const payload = {
              type: 'dislike',
              url: pageUrl,
              referrer_url: pageUrl,
              page_title: deps.limitString(document.title, deps.maxMetadataLen),
              tag_name: 'video',
              width: media.videoWidth || media.clientWidth || null,
              height: media.videoHeight || media.clientHeight || null,
              alt: '',
              preview_url: media.poster || '',
              source: deps.sourceFromMediaUrl(pageUrl),
              download_via: 'yt-dlp',
            };

            emitShortcutReactionState(media, true, 'dislike', payload.url);
            options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
              if (!response || !response.ok) {
                emitShortcutReactionState(media, false, null, payload.url);
                options.showToast(response?.error || 'Reaction failed.', 'danger');
                return;
              }
              emitShortcutReactionState(media, false, 'dislike', payload.url);
              options.showToast('Disliked.');
            });

            return;
          }

          options.showToast('No direct video URL found.');
          return;
        }

        options.showToast('No valid media URL found.');
        return;
      }

      const payload = {
        type: 'dislike',
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

      emitShortcutReactionState(media, true, 'dislike', payload.url);
      options.sendMessageSafe({ type: 'atlas-react', payload }, (response) => {
        if (!response || !response.ok) {
          emitShortcutReactionState(media, false, null, payload.url);
          options.showToast(response?.error || 'Reaction failed.', 'danger');
          return;
        }
        emitShortcutReactionState(media, false, 'dislike', payload.url);
        options.showToast('Disliked.');
      });
    },
    true
  );

  document.addEventListener(
    'auxclick',
    (e) => {
      if (!enabled) return;
      if (!(e instanceof MouseEvent)) return;
      if (!e.altKey || e.button !== 1) return;
      if (options.isSheetOpen()) return;
      if (isOwnUiEvent(e)) return;

      const media = resolveEventMedia(e);
      if (!media) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
    },
    true
  );
}
