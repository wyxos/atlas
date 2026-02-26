import { installHotkeys, installMediaReactionOverlay, type AtlasStatusCacheEntry } from '../interactions';
import { createSendMessageSafe } from '../messaging';
import { createDialogChooser, createToastFn } from '../ui';

type SendMessage = (message: unknown, callback: (response: unknown) => void) => void;

type MountHotkeysOnlyDeps = {
  rootId: string;
  maxMetadataLen: number;
  getMinMediaWidth: () => number;
  getStyleUrl: (path: string) => string;
  sendMessage: SendMessage;
  limitString: (value: unknown, max: number) => string;
  sourceFromMediaUrl: (url: string) => string;
  fetchAtlasStatus: (
    sendMessageSafe: SendMessage,
    url: string,
    referrerUrl: string | null,
    callback: (
      status: {
        exists: boolean;
        downloaded: boolean;
        blacklisted: boolean;
        reactionType: string | null;
        downloadProgress?: number | null;
        downloadedAt?: string | null;
      } | null
    ) => void
  ) => void;
  atlasStatusCache: Map<string, AtlasStatusCacheEntry>;
  getCachedAtlasStatus: (url: string) => {
    exists: boolean;
    downloaded: boolean;
    blacklisted: boolean;
    reactionType: string | null;
    downloadProgress?: number | null;
    downloadedAt?: string | null;
  } | null;
};
type AtlasTestWindow = Window & {
  __ATLAS_TEST_SHADOW_MODE?: unknown;
};

export function mountHotkeysOnly(deps: MountHotkeysOnlyDeps) {
  if (document.getElementById(deps.rootId)) {
    return;
  }

  const host = document.createElement('div');
  host.id = deps.rootId;

  const shadowMode = (() => {
    const override = (window as AtlasTestWindow).__ATLAS_TEST_SHADOW_MODE;
    if (override === 'open' || override === 'closed') {
      return override;
    }

    return document.documentElement.getAttribute('data-atlas-shadow-mode') === 'open'
      ? 'open'
      : 'closed';
  })();
  const shadow = host.attachShadow({ mode: shadowMode });

  const style = document.createElement('link');
  style.rel = 'stylesheet';
  style.href = deps.getStyleUrl('dist/content.css');
  shadow.appendChild(style);

  const root = document.createElement('div');
  root.className = 'atlas-shadow-root';

  const showToast = createToastFn(root);
  const chooseDialog = createDialogChooser(root);
  const sendMessageSafe = createSendMessageSafe(deps.sendMessage, showToast);

  shadow.appendChild(root);
  (document.body || document.documentElement).appendChild(host);

  installHotkeys({
    showToast,
    sendMessageSafe,
    isSheetOpen: () => false,
    chooseDialog,
  }, {
    rootId: deps.rootId,
    get minWidth() {
      return deps.getMinMediaWidth();
    },
    maxMetadataLen: deps.maxMetadataLen,
    limitString: deps.limitString,
    sourceFromMediaUrl: deps.sourceFromMediaUrl,
    fetchAtlasStatus: deps.fetchAtlasStatus,
    atlasStatusCache: deps.atlasStatusCache,
    getCachedAtlasStatus: deps.getCachedAtlasStatus,
  });

  installMediaReactionOverlay({
    root,
    showToast,
    sendMessageSafe,
    isSheetOpen: () => false,
    chooseDialog,
  }, {
    rootId: deps.rootId,
    get minWidth() {
      return deps.getMinMediaWidth();
    },
    maxMetadataLen: deps.maxMetadataLen,
    limitString: deps.limitString,
    sourceFromMediaUrl: deps.sourceFromMediaUrl,
    fetchAtlasStatus: deps.fetchAtlasStatus,
    atlasStatusCache: deps.atlasStatusCache,
    getCachedAtlasStatus: deps.getCachedAtlasStatus,
  });
}
