import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useContainerBlacklists } from './useContainerBlacklists';

// Mock axios
const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    // Reset shared state
    const { blacklists } = useContainerBlacklists();
    blacklists.value = [];
});

describe('useContainerBlacklists', () => {
    it('initializes with empty state', () => {
        const { blacklists, isLoading, error } = useContainerBlacklists();

        expect(blacklists.value).toEqual([]);
        expect(isLoading.value).toBe(false);
        expect(error.value).toBeNull();
    });

    it('fetches blacklisted containers', async () => {
        const mockBlacklists = [
            {
                id: 1,
                type: 'User',
                source: 'CivitAI',
                source_id: '123',
                action_type: 'ui_countdown',
                blacklisted_at: '2024-01-15T10:00:00Z',
            },
            {
                id: 2,
                type: 'User',
                source: 'CivitAI',
                source_id: '456',
                action_type: 'blacklist',
                blacklisted_at: '2024-01-14T10:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue({ data: mockBlacklists });

        const { fetchBlacklists, blacklists, isLoading } = useContainerBlacklists();

        await fetchBlacklists();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/container-blacklists');
        expect(blacklists.value).toEqual(mockBlacklists);
        expect(isLoading.value).toBe(false);
    });

    it('handles fetch errors', async () => {
        const errorMessage = 'Failed to fetch';
        mockAxios.get.mockRejectedValue({
            response: { data: { message: errorMessage } },
        });

        const { fetchBlacklists, error, isLoading } = useContainerBlacklists();

        await fetchBlacklists();

        expect(error.value).toBe(errorMessage);
        expect(isLoading.value).toBe(false);
    });

    it('creates container blacklist', async () => {
        const mockContainer = {
            id: 1,
            type: 'User',
            source: 'CivitAI',
            source_id: '123',
            action_type: 'ui_countdown',
            blacklisted_at: '2024-01-15T10:00:00Z',
        };

        mockAxios.post.mockResolvedValue({ data: mockContainer });

        const { createBlacklist, blacklists } = useContainerBlacklists();

        const result = await createBlacklist(1, 'ui_countdown');

        expect(mockAxios.post).toHaveBeenCalledWith('/api/container-blacklists', {
            container_id: 1,
            action_type: 'ui_countdown',
        });
        expect(result).toEqual(mockContainer);
        expect(blacklists.value.length).toBeGreaterThan(0);
        expect(blacklists.value.find((b) => b.id === mockContainer.id)).toEqual(mockContainer);
    });

    it('updates existing blacklist when creating', async () => {
        const existing = {
            id: 1,
            type: 'User',
            source: 'CivitAI',
            source_id: '123',
            action_type: 'ui_countdown',
            blacklisted_at: '2024-01-15T10:00:00Z',
        };
        const updated = {
            ...existing,
            action_type: 'blacklist',
        };

        const { blacklists, createBlacklist } = useContainerBlacklists();
        blacklists.value.push(existing);

        mockAxios.post.mockResolvedValue({ data: updated });

        await createBlacklist(1, 'blacklist');

        expect(blacklists.value[0]).toEqual(updated);
    });

    it('returns null when actionType is not provided', async () => {
        const { createBlacklist } = useContainerBlacklists();

        const result = await createBlacklist(1, null as any);

        expect(result).toBeNull();
        expect(mockAxios.post).not.toHaveBeenCalled();
    });

    it('deletes container blacklist', async () => {
        const blacklist = {
            id: 1,
            type: 'User',
            source: 'CivitAI',
            source_id: '123',
            action_type: 'ui_countdown',
            blacklisted_at: '2024-01-15T10:00:00Z',
        };

        mockAxios.delete.mockResolvedValue({});

        const { deleteBlacklist, blacklists } = useContainerBlacklists();
        blacklists.value.push(blacklist);

        const result = await deleteBlacklist(1);

        expect(mockAxios.delete).toHaveBeenCalledWith('/api/container-blacklists/1');
        expect(result).toBe(true);
        expect(blacklists.value).not.toContain(blacklist);
    });

    it('handles delete errors', async () => {
        mockAxios.delete.mockRejectedValue({
            response: { data: { message: 'Delete failed' } },
        });

        const { deleteBlacklist, error } = useContainerBlacklists();

        const result = await deleteBlacklist(1);

        expect(result).toBe(false);
        expect(error.value).toBe('Delete failed');
    });

    it('checks container blacklist status', async () => {
        const mockStatus = {
            blacklisted: true,
            blacklisted_at: '2024-01-15T10:00:00Z',
            action_type: 'ui_countdown',
        };

        mockAxios.get.mockResolvedValue({ data: mockStatus });

        const { checkBlacklist } = useContainerBlacklists();

        const result = await checkBlacklist(1);

        expect(mockAxios.get).toHaveBeenCalledWith('/api/container-blacklists/1/check');
        expect(result).toEqual(mockStatus);
    });

    it('handles check errors', async () => {
        mockAxios.get.mockRejectedValue(new Error('Check failed'));

        const { checkBlacklist } = useContainerBlacklists();

        const result = await checkBlacklist(1);

        expect(result).toBeNull();
    });

    it('checks if container is blacklisted from local state', () => {
        const blacklist = {
            id: 1,
            type: 'User',
            source: 'CivitAI',
            source_id: '123',
            action_type: 'ui_countdown',
            blacklisted_at: '2024-01-15T10:00:00Z',
        };

        const { isContainerBlacklisted, blacklists } = useContainerBlacklists();
        blacklists.value.push(blacklist);

        expect(isContainerBlacklisted(1)).toBe(true);
        expect(isContainerBlacklisted(999)).toBe(false);
    });

    it('returns false when container is not in blacklists array', () => {
        const { isContainerBlacklisted, blacklists } = useContainerBlacklists();
        // Ensure blacklists is empty
        blacklists.value = [];
        // Container not in blacklists should return false
        expect(isContainerBlacklisted(999)).toBe(false);
    });
});

