const REACTION_BADGE_CLASS = 'atlas-downloader-reaction-badge';
const REACTION_LAYER_ID = 'atlas-downloader-reaction-badge-layer';
const PAGE_BADGE_ID = 'atlas-downloader-page-visited-badge';

function isElement(node: Node | null): node is Element {
  return node instanceof Element;
}

function isAtlasMarkerElement(element: Element, rootId: string): boolean {
  if (element.id === rootId || Boolean(element.closest?.(`#${rootId}`))) {
    return true;
  }

  if (element.id === REACTION_LAYER_ID || element.id === PAGE_BADGE_ID) {
    return true;
  }

  if (element.classList.contains(REACTION_BADGE_CLASS)) {
    return true;
  }

  return Boolean(element.closest?.(`#${REACTION_LAYER_ID}`));
}

function isAtlasMarkerNode(node: Node | null, rootId: string): boolean {
  return isElement(node) ? isAtlasMarkerElement(node, rootId) : false;
}

export function isAtlasOwnedMutation(
  mutation: MutationRecord,
  rootId: string,
): boolean {
  if (mutation.type === 'attributes') {
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
