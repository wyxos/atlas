import {
  isOwnUiElement,
  parseFileStatusMeta,
  resolveMediaAtPoint,
  type HotkeysOptions,
  type InteractionDependencies,
} from './shared';
import { buildReactionPayloadFromMedia } from './reactionPayload';
import { buildLookupKeys } from '../lookupKeys';

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

  const resolveEventMediaCandidates = (event: MouseEvent): Element[] => {
    const candidates: Element[] = [];
    const seen = new Set<Element>();
    const addCandidate = (candidate: Element | null) => {
      if (!(candidate instanceof Element)) {
        return;
      }

      if (seen.has(candidate)) {
        return;
      }

      seen.add(candidate);
      candidates.push(candidate);
    };

    const target = event.target instanceof Element ? event.target : null;
    const byTarget = target?.closest?.('img, video') ?? null;
    if (byTarget instanceof Element && !isOwnUiElement(byTarget, deps.rootId)) {
      addCandidate(byTarget);
    }

    const byPoint = resolveMediaAtPoint(event.clientX, event.clientY, deps.rootId);
    if (byPoint) {
      addCandidate(byPoint);
    }

    return candidates;
  };

  const resolveEventMedia = (event: MouseEvent): Element | null => {
    return resolveEventMediaCandidates(event)[0] ?? null;
  };

  const resolveEventReactionTarget = (
    event: MouseEvent,
    reactionType: string
  ): {
    media: Element | null;
    payload: ReturnType<typeof buildReactionPayloadFromMedia>;
  } => {
    const candidates = resolveEventMediaCandidates(event);
    for (const candidate of candidates) {
      const payload = buildReactionPayloadFromMedia(candidate, reactionType, deps);
      if (payload) {
        return { media: candidate, payload };
      }
    }

    return {
      media: candidates[0] ?? null,
      payload: null,
    };
  };

  const cacheReactionStatus = (
    payload: {
      url: string;
      preview_url?: string;
      referrer_url?: string;
    },
    file: unknown,
    reactionType: string
  ) => {
    const fileMeta = parseFileStatusMeta(file);
    const status = {
      exists: Boolean(file),
      downloaded: Boolean((file as { downloaded?: unknown } | null)?.downloaded),
      blacklisted: Boolean((file as { blacklisted_at?: unknown } | null)?.blacklisted_at),
      reactionType,
      downloadProgress: fileMeta.downloadProgress,
      downloadedAt: fileMeta.downloadedAt,
      ts: Date.now(),
    };

    for (const lookupKey of buildLookupKeys(payload.url, payload.preview_url || '', payload.referrer_url || '')) {
      deps.atlasStatusCache.set(lookupKey, status);
    }
  };

  const submitReaction = (
    media: Element,
    reactionType: string,
    payload: {
      type: string;
      url: string;
      referrer_url: string;
      preview_url?: string;
      force_download?: boolean;
      download_via?: string;
    },
    optionsOverride: {
      allowRedownloadPrompt: boolean;
      successMessage?: string;
    }
  ) => {
    const requestUrl = payload.url;

    const runSubmit = (forceDownload = false) => {
      const requestPayload = { ...payload };
      if (forceDownload) {
        requestPayload.force_download = true;
      } else if ('force_download' in requestPayload) {
        delete requestPayload.force_download;
      }

      emitShortcutReactionState(media, true, reactionType, requestUrl);
      options.sendMessageSafe({ type: 'atlas-react', payload: requestPayload }, (response) => {
        if (!response || !response.ok) {
          emitShortcutReactionState(media, false, null, requestUrl);
          options.showToast(response?.error || 'Reaction failed.', 'danger');
          return;
        }

        const data = response.data || null;
        const file = data?.file || null;
        const nextReactionType = data?.reaction?.type ? String(data.reaction.type) : reactionType;

        cacheReactionStatus(requestPayload, file, nextReactionType);
        emitShortcutReactionState(media, false, nextReactionType, requestUrl);

        if (optionsOverride.successMessage) {
          options.showToast(optionsOverride.successMessage);
          return;
        }

        if (requestPayload.download_via === 'yt-dlp') {
          options.showToast(`Reacted (${reactionType}). Resolving video in Atlas…`);
          return;
        }

        options.showToast(`Reacted (${reactionType}). Queued download in Atlas.`);
      });
    };

    if (!optionsOverride.allowRedownloadPrompt) {
      runSubmit(false);
      return;
    }

    deps.fetchAtlasStatus(options.sendMessageSafe, payload.url, payload.referrer_url || null, (status) => {
      if (!status?.downloaded) {
        runSubmit(false);
        return;
      }

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

          runSubmit(choice === 'confirm');
        });
    });
  };

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

      const reactionType = e.button === 0 ? 'like' : e.button === 1 ? 'love' : null;
      if (!reactionType) return;

      const resolved = resolveEventReactionTarget(e, reactionType);
      const media = resolved.media;
      if (!media) return;

      maybeHint();

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const payload = resolved.payload;
      if (!payload) {
        if (media instanceof HTMLVideoElement) {
          options.showToast('No direct video URL found.');
          return;
        }

        options.showToast('No valid media URL found.');
        return;
      }

      submitReaction(media, reactionType, payload, {
        allowRedownloadPrompt: true,
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

      const resolved = resolveEventReactionTarget(e, 'dislike');
      const media = resolved.media;
      if (!media) return;

      maybeHint();

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      const payload = resolved.payload;
      if (!payload) {
        if (media instanceof HTMLVideoElement) {
          options.showToast('No direct video URL found.');
          return;
        }

        options.showToast('No valid media URL found.');
        return;
      }

      submitReaction(media, 'dislike', payload, {
        allowRedownloadPrompt: false,
        successMessage: 'Disliked.',
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
