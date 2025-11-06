import axios from 'axios';
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';
import { bus } from '@/lib/bus';

export type ReactionType = 'love' | 'like' | 'dislike' | 'funny';

export function useAudioReactions(loadedFiles: Record<string | number, any>) {
  async function setReaction(item: any, type: ReactionType) {
    const id = item?.id as number | undefined;
    if (!id) return;
    try {
      // Optimistic update
      if (loadedFiles[id]) {
        // Reset all
        loadedFiles[id].loved = false;
        loadedFiles[id].liked = false;
        loadedFiles[id].disliked = false;
        loadedFiles[id].funny = false;
        // Toggle target on if it was previously off
        const wasOn =
          type === 'love'
            ? item.loved
            : type === 'like'
              ? item.liked
              : type === 'dislike'
                ? item.disliked
                : item.funny;
        if (!wasOn) {
          if (type === 'love') loadedFiles[id].loved = true;
          if (type === 'like') loadedFiles[id].liked = true;
          if (type === 'dislike') loadedFiles[id].disliked = true;
          if (type === 'funny') loadedFiles[id].funny = true;
        }
      }

      const action = AudioController.react({ file: id });
      const res = await axios.post(action.url, { type });
      const data = res.data as { loved?: boolean; liked?: boolean; disliked?: boolean; funny?: boolean };

      // Reconcile with server response
      if (loadedFiles[id]) {
        loadedFiles[id].loved = !!data.loved;
        loadedFiles[id].liked = !!data.liked;
        loadedFiles[id].disliked = !!data.disliked;
        loadedFiles[id].funny = !!data.funny;
      }

      // Broadcast reaction so other parts of the app can sync
      bus.emit('file:reaction', {
        id,
        loved: !!data.loved,
        liked: !!data.liked,
        disliked: !!data.disliked,
        funny: !!data.funny,
      });
    } catch (e) {
      console.error('Failed to set reaction', e);
    }
  }

  function toggleFavorite(item: any) { void setReaction(item, 'love'); }
  function likeItem(item: any) { void setReaction(item, 'like'); }
  function dislikeItem(item: any) { void setReaction(item, 'dislike'); }
  function laughedAtItem(item: any) { void setReaction(item, 'funny'); }

  return { setReaction, toggleFavorite, likeItem, dislikeItem, laughedAtItem };
}
