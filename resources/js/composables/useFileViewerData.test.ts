import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, reactive, nextTick } from 'vue';
import { flushPromises } from '@vue/test-utils';
import { useFileViewerData } from './useFileViewerData';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
};

vi.mock('axios', () => ({
    default: mockAxios,
}));

vi.mock('@/actions/App/Http/Controllers/FilesController', () => ({
    incrementSeen: {
        url: (id: number) => `/api/files/${id}/seen`,
    },
    show: {
        url: (id: number) => `/api/files/${id}`,
    },
}));

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('useFileViewerData', () => {
    it('fetches file data when navigating after overlay fill completes', async () => {
        mockAxios.get
            .mockResolvedValueOnce({ data: { file: { id: 1, filename: 'one.jpg' } } })
            .mockResolvedValueOnce({ data: { file: { id: 2, filename: 'two.jpg' } } });

        const items = ref([{ id: 1 }, { id: 2 }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: false });
        const sheet = reactive({ isOpen: true });

        const { fileData, isLoadingFileData } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        // Sheet is open but overlay isn't filled yet -> no fetch, but we should not show stale data.
        expect(mockAxios.get).not.toHaveBeenCalled();
        expect(fileData.value).toBeNull();
        expect(isLoadingFileData.value).toBe(true);

        overlay.fillComplete = true;
        await nextTick();
        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/files/1');
        expect(fileData.value?.id).toBe(1);
        expect(isLoadingFileData.value).toBe(false);

        // Navigate to the next file. Overlay transitions set fillComplete=false first.
        overlay.fillComplete = false;
        navigation.currentItemIndex = 1;
        await nextTick();

        expect(fileData.value).toBeNull();
        expect(isLoadingFileData.value).toBe(true);

        overlay.fillComplete = true;
        await nextTick();
        await flushPromises();

        expect(mockAxios.get).toHaveBeenLastCalledWith('/api/files/2');
        expect(fileData.value?.id).toBe(2);
        expect(isLoadingFileData.value).toBe(false);
    });
});

