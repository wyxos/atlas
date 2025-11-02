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
}
