import mitt from 'mitt';

export type Events = {
  'file:reaction': {
    id: number;
    loved: boolean;
    liked: boolean;
    disliked: boolean;
    funny: boolean;
  };
  // Player helpers
  'player:scroll-to-current': { id: number };
  // Spotify auth lifecycle
  'spotify:auth:invalid': { reason: string };
  // Highlight related items across the grid by shared containers (generic)
  'browse:highlight-containers': {
    sourceId?: number;
    entries: Array<{ key: string; label: string; value: string | number; count: number; slotIndex: number }>;
  };
  'browse:clear-highlight': void;
  'browse:refresh': void;
  // Moderation aggregation notifications
  'moderation:notify': { ids: number[]; previews: string[]; previewTitles: string[]; count: number };
};

export const bus = mitt<Events>();

