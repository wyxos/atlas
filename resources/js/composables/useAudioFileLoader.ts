import axios from 'axios';
import { reactive, ref } from 'vue';
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';

export function useAudioFileLoader() {
    // Store for loaded file details
    const loadedFiles = reactive<Record<string, any>>({});

    // Store for in-progress requests
    const pendingRequests = reactive<Record<string | number, AbortController>>({});

    // Visible items tracking
    const observedItems = ref<Set<string | number>>(new Set());
    const visibleItems = ref<Set<string | number>>(new Set());

    // Scrolling state
    const isScrolling = ref(false);
    const scrollTimeout = ref<number | null>(null);

    // Function to load file details with cancellation support
    async function loadFileDetails(fileId: string | number, priority: boolean = false) {
        if (loadedFiles[fileId]) {
            return loadedFiles[fileId]; // Return cached data if already loaded
        }

        // Cancel any existing request for this file if not priority
        if (pendingRequests[fileId] && !priority) {
            pendingRequests[fileId].abort();
            delete pendingRequests[fileId];
        }

        // Create a new abort controller for this request
        const controller = new AbortController();
        pendingRequests[fileId] = controller;

        try {
            const action = AudioController.details({ file: fileId });
            const response = await axios.get(action.url, {
                signal: controller.signal,
            });
            loadedFiles[fileId] = response.data;
            delete pendingRequests[fileId];
            return response.data;
        } catch (error) {
            if (axios.isCancel(error)) {
                // Request canceled for this file
            } else {
                console.error('Error loading file details:', error);
            }
            delete pendingRequests[fileId];
            return null;
        }
    }

    // Function to load multiple file details in a single batch request
    async function loadBatchFileDetails(fileIds: (string | number)[]) {
        if (fileIds.length === 0) {
            return;
        }

        // Filter out files that are already loaded or have pending requests
        const idsToLoad = fileIds.filter((id) => !loadedFiles[id] && !pendingRequests[id]);

        if (idsToLoad.length === 0) {
            return;
        }

        // Create abort controller for batch request cancellation
        const controller = new AbortController();

        // Mark all files as having pending requests
        idsToLoad.forEach((id) => {
            pendingRequests[id] = controller;
        });

        try {
            const action = AudioController.batchDetails();
            const response = await axios.post(
                action.url,
                {
                    file_ids: idsToLoad,
                },
                {
                    signal: controller.signal,
                },
            );

            // Update loadedFiles with the batch response
            const batchData = response.data;
            Object.keys(batchData).forEach((fileId) => {
                loadedFiles[fileId] = batchData[fileId];
                delete pendingRequests[fileId];
            });
        } catch (error) {
            if (axios.isCancel(error)) {
                // Batch request canceled for files
            } else {
                console.error('Error loading batch file details:', error);
            }

            // Clean up pending requests on error
            idsToLoad.forEach((id) => {
                delete pendingRequests[id];
            });
        }
    }

    // Function to get file data (either from cache or props)
    function getFileData(item: any) {
        return loadedFiles[item.id] || item;
    }

    // Handle scroll events
    function handleScroll() {
        isScrolling.value = true;

        // Cancel all pending requests when scrolling resumes
        // Only cancel requests for items that are not currently visible
        Object.keys(pendingRequests).forEach((key) => {
            if (!visibleItems.value.has(key)) {
                pendingRequests[key].abort();
                delete pendingRequests[key];
            }
        });

        // Clear previous timeout
        if (scrollTimeout.value !== null) {
            window.clearTimeout(scrollTimeout.value);
        }

        // Set a timeout to detect when scrolling stops
        scrollTimeout.value = window.setTimeout(() => {
            isScrolling.value = false;
            prioritizeVisibleItems();
        }, 500); // 500ms debounce
    }

    // Prioritize loading of currently visible items
    function prioritizeVisibleItems() {
        // First, clean up the visibleItems set by checking which items are actually still visible
        const currentlyVisible = new Set<string | number>();

        // Check each observed item to see if it's actually still intersecting
        observedItems.value.forEach((itemId) => {
            const element = document.querySelector(`[data-item-id="${itemId}"]`);
            if (element) {
                const rect = element.getBoundingClientRect();
                const viewportHeight = window.innerHeight;

                // Check if the element is actually visible in the viewport
                if (rect.top < viewportHeight && rect.bottom > 0) {
                    currentlyVisible.add(itemId);
                }
            }
        });

        // Update visibleItems to only include actually visible items
        visibleItems.value = currentlyVisible;

        // Collect file IDs that need to be loaded
        const fileIdsToLoad: (string | number)[] = [];
        currentlyVisible.forEach((itemId) => {
            if (!loadedFiles[itemId]) {
                fileIdsToLoad.push(itemId);
            }
        });

        // Load all needed files in a single batch request
        if (fileIdsToLoad.length > 0) {
            loadBatchFileDetails(fileIdsToLoad);
        }

        // Cancel any pending requests for items that are no longer visible
        Object.keys(pendingRequests).forEach((itemId) => {
            if (!currentlyVisible.has(itemId)) {
                pendingRequests[itemId].abort();
                delete pendingRequests[itemId];
            }
        });
    }

    // Setup observation for an item
    function observeItem(el: HTMLElement, itemId: string | number, observer: IntersectionObserver) {
        if (observer && el) {
            el.setAttribute('data-item-id', String(itemId));
            observer.observe(el);
        }
    }

    // Create and setup intersection observer
    function createObserver() {
        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    const itemId = entry.target.getAttribute('data-item-id');
                    if (!itemId) return;

                    if (entry.isIntersecting) {
                        // Item is now visible - but don't add to visibleItems during fast scrolling
                        // Only track it in observedItems for potential cleanup
                        observedItems.value.add(itemId);

                        // Only add to visibleItems if we're not scrolling or scrolling slowly
                        if (!isScrolling.value) {
                            visibleItems.value.add(itemId);

                            // Only load if not already loaded or loading
                            if (!loadedFiles[itemId] && !pendingRequests[itemId]) {
                                loadFileDetails(itemId, true); // Priority load when not scrolling
                            }
                        }
                        // During scrolling, don't add to visibleItems - prioritizeVisibleItems will handle it
                    } else {
                        // Item is no longer visible - remove from both sets
                        visibleItems.value.delete(itemId);
                        observedItems.value.delete(itemId);

                        // If there's a pending request, cancel it
                        if (pendingRequests[itemId]) {
                            pendingRequests[itemId].abort();
                            delete pendingRequests[itemId];
                        }
                    }
                });
            },
            { threshold: 0.1 },
        );

        return observer;
    }

    // Cleanup function
    function cleanup(observer: IntersectionObserver | null) {
        if (observer) {
            observer.disconnect();
        }

        // Clear any pending timeout
        if (scrollTimeout.value !== null) {
            window.clearTimeout(scrollTimeout.value);
        }

        // Cancel all pending requests
        Object.values(pendingRequests).forEach((controller) => {
            controller.abort();
        });
    }

    return {
        loadedFiles,
        loadFileDetails,
        loadBatchFileDetails,
        getFileData,
        handleScroll,
        observeItem,
        createObserver,
        cleanup,
        isScrolling,
        visibleItems,
        observedItems,
    };
}
