const REACTION_BADGE_CLASS = 'atlas-downloader-reaction-badge';
const REACTION_LAYER_ID = 'atlas-downloader-reaction-badge-layer';
const OPEN_TAB_BADGE_CLASS = 'atlas-downloader-open-tab-badge';
const OPEN_TAB_LAYER_ID = 'atlas-downloader-open-tab-badge-layer';
const PAGE_BADGE_ID = 'atlas-downloader-page-visited-badge';
const INLINE_BADGE_CLASS = 'atlas-downloader-inline-badge';
const MARKER_RAIL_CLASS = 'atlas-downloader-marker-rail';
const MARKER_RAIL_ATTR = 'data-atlas-marker-rail';
const MARKER_BADGE_ATTR = 'data-atlas-marker-badge';
const MARKER_HOST_POSITION_ATTR = 'data-atlas-marker-host-position';

function isElement(node: Node | null): node is Element {
  return node instanceof Element;
}

function isAtlasMarkerElement(element: Element, rootId: string): boolean {
  if (element.id === rootId || Boolean(element.closest?.(`#${rootId}`))) {
    return true;
  }

  if (
    element.id === REACTION_LAYER_ID
    || element.id === OPEN_TAB_LAYER_ID
    || element.id === PAGE_BADGE_ID
  ) {
    return true;
  }

  if (element.classList.contains(REACTION_BADGE_CLASS) || element.classList.contains(OPEN_TAB_BADGE_CLASS)) {
    return true;
  }

  if (element.classList.contains(INLINE_BADGE_CLASS) || element.classList.contains(MARKER_RAIL_CLASS)) {
    return true;
  }

  if (
    element.hasAttribute(MARKER_RAIL_ATTR)
    || element.hasAttribute(MARKER_BADGE_ATTR)
    || element.hasAttribute(MARKER_HOST_POSITION_ATTR)
  ) {
    return true;
  }

  return Boolean(
    element.closest?.(`#${REACTION_LAYER_ID}`)
    || element.closest?.(`#${OPEN_TAB_LAYER_ID}`)
    || element.closest?.(`[${MARKER_RAIL_ATTR}]`)
    || element.closest?.(`[${MARKER_BADGE_ATTR}]`)
    || element.closest?.(`[${MARKER_HOST_POSITION_ATTR}]`)
  );
}

function isAtlasMarkerNode(node: Node | null, rootId: string): boolean {
  return isElement(node) ? isAtlasMarkerElement(node, rootId) : false;
}

export function isAtlasOwnedMutation(
  mutation: MutationRecord,
  rootId: string,
): boolean {
  if (mutation.type === 'attributes') {
    if (
      isElement(mutation.target)
      && mutation.attributeName === 'style'
      && mutation.target.getAttribute(MARKER_HOST_POSITION_ATTR) === '1'
    ) {
      return true;
    }

    return isAtlasMarkerNode(mutation.target, rootId);
  }

  const changedNodes = [...mutation.addedNodes, ...mutation.removedNodes];
  if (changedNodes.length === 0) {
    return false;
  }

  return changedNodes.every((node) => isAtlasMarkerNode(node, rootId));
}

export function shouldIgnoreMutationBatch(
  mutations: MutationRecord[],
  rootId: string,
): boolean {
  if (mutations.length === 0) {
    return false;
  }

  return mutations.every((mutation) => isAtlasOwnedMutation(mutation, rootId));
}
