export type ReactionAction = {
  type: string;
  label: string;
  className: string;
  pathDs: string[];
};

const SVG_NS = 'http://www.w3.org/2000/svg';

export const createSvgIcon = (pathDs: string[]): SVGSVGElement => {
  const svg = document.createElementNS(SVG_NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke-width', '2');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  svg.setAttribute('width', '18');
  svg.setAttribute('height', '18');

  for (const d of pathDs) {
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', d);
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '2');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(path);
  }

  return svg;
};

export const REACTIONS: ReactionAction[] = [
  {
    type: 'love',
    label: 'Favorite',
    className: 'love',
    pathDs: [
      'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78Z',
    ],
  },
  {
    type: 'like',
    label: 'Like',
    className: 'like',
    pathDs: [
      'M7 10v12',
      'M15 5.88 14 10h6.14a2 2 0 0 1 1.94 2.46l-2.34 8.25A2 2 0 0 1 17.82 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.34a2 2 0 0 0 1.79-1.11l3.07-5.89A2 2 0 0 1 15 2a2 2 0 0 1 2 2v1.88Z',
    ],
  },
  {
    type: 'dislike',
    label: 'Dislike',
    className: 'dislike',
    pathDs: [
      'M17 14V2',
      'M9 18.12 10 14H3.86a2 2 0 0 1-1.94-2.46L4.26 3.29A2 2 0 0 1 6.18 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.34a2 2 0 0 0-1.79 1.11l-3.07 5.89A2 2 0 0 1 9 22a2 2 0 0 1-2-2v-1.88Z',
    ],
  },
  {
    type: 'funny',
    label: 'Funny',
    className: 'funny',
    pathDs: [
      'M8 14s1.5 2 4 2 4-2 4-2',
      'M9 9h.01',
      'M15 9h.01',
      'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0Z',
    ],
  },
];

export const BLACKLIST_ACTION: ReactionAction = {
  type: 'blacklist',
  label: 'Blacklist',
  className: 'blacklist',
  pathDs: ['M18 6 6 18', 'M6 6l12 12', 'M3 12a9 9 0 1 0 18 0 9 9 0 0 0-18 0Z'],
};
