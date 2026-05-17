import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import Audio from './Audio.vue';

export async function mountAudioPage(initialPath = '/playlists/all') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            {
                path: '/playlists/:playlistSlug',
                name: 'audio',
                component: Audio,
            },
        ],
    });

    await router.push(initialPath);
    await router.isReady();

    const wrapper = mount(Audio, {
        global: {
            plugins: [router],
        },
    });

    return { router, wrapper };
}

export async function mountAudio(initialPath = '/playlists/all') {
    return (await mountAudioPage(initialPath)).wrapper;
}
