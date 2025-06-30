import { ref } from 'vue';

export function useAudioSwipeHandler() {
  const swipedItemId = ref<string | null>(null);
  const startX = ref<number | null>(null);
  const endX = ref<number | null>(null);
  const swipeThreshold = 50; // Minimum distance to trigger swipe
  const isDragging = ref(false);

  // Handle touch/mouse start
  function handleTouchStart(event: TouchEvent | MouseEvent): void {
    if ('touches' in event) {
      startX.value = event.touches[0].clientX;
    } else {
      isDragging.value = true;
      startX.value = event.clientX;
    }
  }

  // Handle touch/mouse move
  function handleTouchMove(event: TouchEvent | MouseEvent): void {
    if ('touches' in event) {
      endX.value = event.touches[0].clientX;
    } else if (isDragging.value) {
      endX.value = event.clientX;
    }
  }

  // Handle touch/mouse end
  function handleTouchEnd(item: any): void {
    if (!startX.value || !endX.value) return;

    const swipeDistance = startX.value - endX.value;

    // If swiped left beyond threshold
    if (swipeDistance > swipeThreshold) {
      // If this item is already open, close it
      if (swipedItemId.value === item.id) {
        swipedItemId.value = null;
      } else {
        // Open this item, closing any previously open item
        swipedItemId.value = item.id;
      }
    }
    // If swiped right beyond threshold
    else if (swipeDistance < -swipeThreshold) {
      // Close the item if it's open
      if (swipedItemId.value === item.id) {
        swipedItemId.value = null;
      }
    }

    // Reset coordinates and dragging state
    startX.value = null;
    endX.value = null;
    isDragging.value = false;
  }

  // Close any open item when clicking outside
  function handleGlobalClick(): void {
    if (swipedItemId.value) {
      swipedItemId.value = null;
    }
  }

  return {
    swipedItemId,
    isDragging,
    handleTouchStart,
    handleTouchMove,
    handleTouchEnd,
    handleGlobalClick
  };
}
