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

    it('updates active sheet data and matching feed item after source media refresh', () => {
        const items = ref([{
            id: 1,
            src: 'https://images.example.test/old-preview.jpg',
            preview: 'https://images.example.test/old-preview.jpg',
            original: 'https://images.example.test/old-original.jpg',
            url: 'https://images.example.test/old-original.jpg',
        }] as any[]);
        const navigation = reactive({ currentItemIndex: 0 as number | null });
        const overlay = reactive({ fillComplete: true });
        const sheet = reactive({ isOpen: false });

        const { fileData, setFileData } = useFileViewerData({
            items,
            navigation,
            overlay,
            sheet,
        });

        setFileData({
            id: 1,
            url: 'https://images.example.test/fresh-original.png',
            file_url: 'https://images.example.test/fresh-original.png',
            preview_url: 'https://images.example.test/fresh-preview.jpg',
            previewed_count: 3,
            seen_count: 4,
            auto_blacklisted: false,
            blacklisted_at: null,
            downloaded: false,
            not_found: false,
        } as any);

        expect(fileData.value?.id).toBe(1);
        expect(items.value[0].src).toBe('https://images.example.test/fresh-preview.jpg');
        expect(items.value[0].preview).toBe('https://images.example.test/fresh-preview.jpg');
        expect(items.value[0].original).toBe('https://images.example.test/fresh-original.png');
        expect(items.value[0].originalUrl).toBe('https://images.example.test/fresh-original.png');
        expect(items.value[0].previewed_count).toBe(3);
        expect(items.value[0].seen_count).toBe(4);
    });
});
