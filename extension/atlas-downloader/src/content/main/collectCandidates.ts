import { buildDirectPageCandidate, buildItemFromElement } from '../items';

type CandidateItem = NonNullable<ReturnType<typeof buildItemFromElement>>;

type CollectCandidatesDeps = {
  rootId: string;
  getMinMediaWidth: () => number;
};

export function collectCandidates(
  deps: CollectCandidatesDeps,
  onProgress?: (progress: { scanned: number; total: number }) => void
): Promise<CandidateItem[]> {
  return new Promise((resolve) => {
    const root = document.body || document.documentElement;
    const nodes = Array.from(root.querySelectorAll('img, video'));
    const total = nodes.length;
    const seen = new Set<string>();
    const items: CandidateItem[] = [];
    let index = 0;

    const schedule = (fn: (deadline?: { timeRemaining?: () => number }) => void) => {
      if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(fn, { timeout: 500 });
        return;
      }

      setTimeout(() => fn({ timeRemaining: () => 25 }), 0);
    };

    const work = (deadline?: { timeRemaining?: () => number }) => {
      const timeRemaining =
        typeof deadline?.timeRemaining === 'function' ? deadline.timeRemaining() : 25;

      let steps = 0;
      while (index < total && (steps < 80 || timeRemaining > 5)) {
        const element = nodes[index];
        if (element.closest && element.closest(`#${deps.rootId}`)) {
          index += 1;
          steps += 1;
          continue;
        }

        const item = buildItemFromElement(element, deps.getMinMediaWidth());
        if (item?.url && !seen.has(item.url)) {
          seen.add(item.url);
          items.push(item);
        }

        index += 1;
        steps += 1;
      }

      onProgress?.({ scanned: index, total });

      if (index < total) {
        schedule(work);
        return;
      }

      const direct = buildDirectPageCandidate();
      if (direct && !seen.has(direct.url)) {
        seen.add(direct.url);
        items.push(direct);
      }

      resolve(items);
    };

    schedule(work);
  });
}
