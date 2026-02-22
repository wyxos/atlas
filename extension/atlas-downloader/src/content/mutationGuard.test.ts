// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { shouldIgnoreMutationBatch } from './mutationGuard';

function asNodeList(nodes: Node[]): NodeList {
  return nodes as unknown as NodeList;
}

function childListMutation(added: Node[] = [], removed: Node[] = []): MutationRecord {
  return {
    type: 'childList',
    target: document.body,
    addedNodes: asNodeList(added),
    removedNodes: asNodeList(removed),
    previousSibling: null,
    nextSibling: null,
    attributeName: null,
    attributeNamespace: null,
    oldValue: null,
  } as MutationRecord;
}

function attributesMutation(target: Element): MutationRecord {
  return {
    type: 'attributes',
    target,
    addedNodes: asNodeList([]),
    removedNodes: asNodeList([]),
    previousSibling: null,
    nextSibling: null,
    attributeName: 'style',
    attributeNamespace: null,
    oldValue: null,
  } as MutationRecord;
}

describe('shouldIgnoreMutationBatch', () => {
  it('ignores marker-only child list mutations', () => {
    const layer = document.createElement('div');
    layer.id = 'atlas-downloader-reaction-badge-layer';

    const badge = document.createElement('span');
    badge.className = 'atlas-downloader-reaction-badge like';
    layer.appendChild(badge);

    expect(shouldIgnoreMutationBatch([childListMutation([layer])], 'atlas-downloader-root')).toBe(true);
    expect(shouldIgnoreMutationBatch([childListMutation([], [badge])], 'atlas-downloader-root')).toBe(true);
  });

  it('ignores marker attribute mutations', () => {
    const badge = document.createElement('span');
    badge.className = 'atlas-downloader-reaction-badge';

    expect(shouldIgnoreMutationBatch([attributesMutation(badge)], 'atlas-downloader-root')).toBe(true);
  });

  it('does not ignore page content mutations', () => {
    const img = document.createElement('img');
    expect(shouldIgnoreMutationBatch([childListMutation([img])], 'atlas-downloader-root')).toBe(false);
  });

  it('does not ignore mixed batches', () => {
    const badge = document.createElement('span');
    badge.className = 'atlas-downloader-reaction-badge';
    const img = document.createElement('img');

    expect(
      shouldIgnoreMutationBatch(
        [childListMutation([badge]), childListMutation([img])],
        'atlas-downloader-root',
      ),
    ).toBe(false);
  });
});
