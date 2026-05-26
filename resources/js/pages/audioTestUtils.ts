import { mount } from '@vue/test-utils';
import { createMemoryHistory, createRouter } from 'vue-router';
import Audio from './Audio.vue';
import AudioPlaylists from './AudioPlaylists.vue';

export async function mountAudioPage(initialPath = '/playlists/all') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            {
                path: '/audio',
                name: 'audio-playlists',
                component: AudioPlaylists,
            },
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

export async function mountAudioPlaylistGrid(initialPath = '/audio') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            {
                path: '/audio',
                name: 'audio-playlists',
                component: AudioPlaylists,
            },
            {
                path: '/playlists/:playlistSlug',
                name: 'audio',
                component: Audio,
            },
        ],
    });

    await router.push(initialPath);
    await router.isReady();

    const wrapper = mount(AudioPlaylists, {
        global: {
            plugins: [router],
        },
    });

    return { router, wrapper };
}
