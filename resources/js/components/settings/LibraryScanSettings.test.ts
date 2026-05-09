import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import LibraryScanSettings from './LibraryScanSettings.vue';

type AxiosMock = {
    get: ReturnType<typeof vi.fn>;
    post: ReturnType<typeof vi.fn>;
};

type EchoMock = {
    leave: ReturnType<typeof vi.fn>;
    private: ReturnType<typeof vi.fn>;
};

type ScanRunOverrides = Partial<{
    id: number;
    mode: string;
    status: string;
    phase: string | null;
    files_found: number;
    files_imported: number;
    files_duplicate: number;
    files_processed: number;
    files_failed: number;
    files_canceled: number;
    error: string | null;
}>;

function makeRun(overrides: ScanRunOverrides = {}) {
    return {
        id: 12,
        mode: 'scan',
        status: 'processing',
        phase: 'video',
        files_found: 4,
        files_imported: 3,
        files_duplicate: 1,
        files_processed: 1,
        files_failed: 1,
        files_canceled: 0,
        started_at: '2026-05-05T10:00:00Z',
        scan_completed_at: '2026-05-05T10:01:00Z',
        finished_at: null,
        paused_at: null,
        canceled_at: null,
        error: null,
        created_at: '2026-05-05T10:00:00Z',
        updated_at: '2026-05-05T10:02:00Z',
        ...overrides,
    };
}

function makeItem() {
    return {
        id: 25,
        library_scan_run_id: 12,
        file_id: 400,
        original_path: 'D:/atlas/video.mov',
        imported_path: 'imports/aa/bb/video.mov',
        hash: 'a'.repeat(64),
        mime_type: 'video/quicktime',
        size: 1200,
        status: 'failed',
        phase: 'video',
        progress: 70,
        duplicate: false,
        parser: 'video',
        error_code: 'parser_failed',
        error_message: 'ffmpeg failed',
        error_context: { command: 'ffmpeg' },
        created_at: '2026-05-05T10:00:00Z',
        updated_at: '2026-05-05T10:02:00Z',
    };
}

function installEchoMock(): EchoMock {
    const channel = { listen: vi.fn().mockReturnThis() };
    const echo = {
        leave: vi.fn(),
        private: vi.fn(() => channel),
    };

    (window as unknown as { Echo: EchoMock }).Echo = echo;

    return echo;
}

function installAxiosMock(run = makeRun(), items = [makeItem()]): AxiosMock {
    const axios = {
        get: vi.fn((url: string) => {
            if (url === '/api/settings/library-scans') {
                return Promise.resolve({ data: { items: [run] } });
            }

            return Promise.resolve({
                data: {
                    run,
                    items,
                    pagination: {
                        limit: 100,
                        next_cursor: null,
                        previous_cursor: null,
                        has_more: false,
                    },
                },
            });
        }),
        post: vi.fn(() => Promise.resolve({ data: { run } })),
    };

    (window as unknown as { axios: AxiosMock }).axios = axios;

    return axios;
}

async function mountScanSettings() {
    const wrapper = mount(LibraryScanSettings);
    await flushPromises();

    return wrapper;
}

afterEach(() => {
    vi.restoreAllMocks();
    (window as unknown as { Echo?: EchoMock }).Echo = undefined;
});

describe('LibraryScanSettings', () => {
    it('renders scan progress and per-file parser errors', async () => {
        installAxiosMock();
        installEchoMock();

        const wrapper = await mountScanSettings();

        expect(wrapper.text()).toContain('processing');
        expect(wrapper.text()).toContain('video');
        expect(wrapper.text()).toContain('50%');
        expect(wrapper.text()).toContain('imports/aa/bb/video.mov');
        expect(wrapper.text()).toContain('ffmpeg failed');
    });

    it('enables resume and restart controls for paused scans', async () => {
        const run = makeRun({ status: 'paused', phase: 'paused' });
        const axios = installAxiosMock(run, []);
        installEchoMock();

        const wrapper = await mountScanSettings();
        const buttons = wrapper.findAll('button');

        expect(buttons.find((button) => button.text() === 'Pause')?.attributes('disabled')).toBeDefined();
        expect(buttons.find((button) => button.text() === 'Resume')?.attributes('disabled')).toBeUndefined();
        expect(buttons.find((button) => button.text() === 'Restart')?.attributes('disabled')).toBeUndefined();

        await buttons.find((button) => button.text() === 'Resume')?.trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/api/settings/library-scans/12/resume');
    });

    it('starts imported file parser reruns from settings', async () => {
        const run = makeRun({ id: 18, mode: 'reparse', status: 'pending', phase: 'reparse_pending' });
        const axios = installAxiosMock(run, []);
        installEchoMock();

        const wrapper = await mountScanSettings();

        await wrapper.findAll('button').find((button) => button.text() === 'Re-run Parsers')?.trigger('click');
        await flushPromises();

        expect(axios.post).toHaveBeenCalledWith('/api/settings/library-scans/reparse-imported');
        expect(wrapper.text()).toContain('Imported file parser re-run queued.');
        expect(wrapper.text()).toContain('Parser re-run');
    });
});
