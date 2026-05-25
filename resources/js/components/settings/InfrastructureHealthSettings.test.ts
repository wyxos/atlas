import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import InfrastructureHealthSettings from './InfrastructureHealthSettings.vue';

function installAxiosMock() {
    const axios = {
        get: vi.fn((url: string) => {
            if (url === '/api/settings/infrastructure-health') {
                return Promise.resolve({
                    data: {
                        checked_at: '2026-05-25T11:00:00.000000Z',
                        typesense: {
                            ok: true,
                            status: 'healthy',
                            endpoint: 'http://localhost:8108',
                            message: 'Typesense health endpoint responded.',
                            latency_ms: 12,
                            response_ok: true,
                        },
                        storage: {
                            ok: true,
                            status: 'healthy',
                            disk: 'atlas',
                            root: 'D:/Atlas',
                            app_root: 'D:/Atlas/.app',
                            root_exists: true,
                            app_root_exists: true,
                            readable: true,
                            writable: true,
                            write_probe: true,
                            read_probe: true,
                            delete_probe: true,
                            free_bytes: 1024,
                            total_bytes: 2048,
                            namespaces: [
                                { name: 'downloads', exists: true },
                                { name: 'imports', exists: false },
                            ],
                            message: 'Atlas storage accepted a write/read/delete probe.',
                            latency_ms: 8,
                        },
                    },
                });
            }

            return Promise.reject(new Error(`Unexpected GET ${url}`));
        }),
    };

    (window as unknown as { axios: typeof axios }).axios = axios;

    return axios;
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('InfrastructureHealthSettings', () => {
    it('pings infrastructure health from settings', async () => {
        const axios = installAxiosMock();
        const wrapper = mount(InfrastructureHealthSettings);

        expect(wrapper.get('[data-test="infrastructure-health"]').text()).toContain('Not checked');
        expect(wrapper.get('[data-test="typesense-health"]').text()).toContain('Run a ping to check Typesense.');
        expect(wrapper.get('[data-test="storage-health"]').text()).toContain('Run a ping to check storage.');

        await wrapper.get('[data-test="ping-infrastructure-health"]').trigger('click');
        await flushPromises();

        expect(axios.get).toHaveBeenCalledWith('/api/settings/infrastructure-health');
        expect(wrapper.get('[data-test="typesense-health"]').text()).toContain('Healthy');
        expect(wrapper.get('[data-test="typesense-health"]').text()).toContain('http://localhost:8108');
        expect(wrapper.get('[data-test="storage-health"]').text()).toContain('Healthy');
        expect(wrapper.get('[data-test="storage-health"]').text()).toContain('Write');
        expect(wrapper.get('[data-test="storage-health"]').text()).toContain('Passed');
        expect(wrapper.get('[data-test="storage-health"]').text()).toContain('imports missing');
        expect(wrapper.text()).toContain('Infrastructure checks passed.');
    });
});
