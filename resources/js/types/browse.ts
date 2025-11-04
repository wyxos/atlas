export interface BrowseContainer {
  label: 'user' | 'post';
  key: 'username' | 'postId';
  value: string | number | null;
}

export interface BrowseItem {
  id: number;
  preview: string | null;
  original: string | null;
  type?: 'image' | 'video' | 'audio' | 'other';
  width?: number | null;
  height?: number | null;
  page?: number | null;
  containers?: BrowseContainer[];
  loved?: boolean;
  liked?: boolean;
  disliked?: boolean;
  funny?: boolean;
  referrer_url?: string | null;
  true_original_url?: string | null;
  true_thumbnail_url?: string | null;
  is_local?: boolean;
}
