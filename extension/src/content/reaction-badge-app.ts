import { createApp, defineComponent, h } from 'vue';
import { Heart, Smile, ThumbsDown, ThumbsUp } from 'lucide-vue-next';

type MountedBadge = {
    element: HTMLDivElement;
    unmount: () => void;
};

const iconBaseStyle = {
    width: '18px',
    height: '18px',
    strokeWidth: 2,
    color: '#ffffff',
} as const;

const reactionButtonStyle = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: '4px',
    padding: '8px',
} as const;

const AtlasReactionBadge = defineComponent({
    name: 'AtlasReactionBadge',
    setup() {
        return () => h(
            'div',
            {
                style: {
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    borderRadius: '8px',
                    background: 'rgba(0, 0, 0, 0.6)',
                    backdropFilter: 'blur(8px)',
                    color: '#ffffff',
                    pointerEvents: 'none',
                    gap: '8px',
                    padding: '8px 16px',
                },
            },
            [
                h('div', { style: reactionButtonStyle }, [h(Heart, iconBaseStyle)]),
                h('div', { style: reactionButtonStyle }, [h(ThumbsUp, iconBaseStyle)]),
                h('div', { style: reactionButtonStyle }, [h(ThumbsDown, iconBaseStyle)]),
                h('div', { style: reactionButtonStyle }, [h(Smile, iconBaseStyle)]),
            ],
        );
    },
});

export function createReactionBadgeHost(): MountedBadge {
    const element = document.createElement('div');
    const app = createApp(AtlasReactionBadge);
    app.mount(element);

    return {
        element,
        unmount: () => {
            app.unmount();
        },
    };
}
