export const DEFAULT_MEDIA_NOISE_FILTERS = [
  'host:st.deviantart.net',
  'url:*wixmp.com*/crop/w_92,h_92*',
  'url:*wixmp.com*/crop/w_150,h_150*',
  'url:*wixmp.com*/fit/w_150,h_150*',
] as const;

export const DEFAULT_MEDIA_NOISE_FILTERS_TEXT = DEFAULT_MEDIA_NOISE_FILTERS.join('\n');
