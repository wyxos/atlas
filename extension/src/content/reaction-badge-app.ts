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
} as const;

const AtlasReactionBadge = defineComponent({
    name: 'AtlasReactionBadge',
    setup() {
        return () => h(
            'div',
            {
                style: {
                    width: '100%',
                    height: '100%',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    borderRadius: '8px',
                    background: 'rgba(0, 0, 0, 0.6)',
                    backdropFilter: 'blur(8px)',
                    color: '#ffffff',
                    pointerEvents: 'none',
                    gap: '12px',
                },
            },
            [
                h(Heart, { ...iconBaseStyle, color: '#f87171' }),
                h(ThumbsUp, { ...iconBaseStyle, color: '#60a5fa' }),
                h(ThumbsDown, { ...iconBaseStyle, color: '#9ca3af' }),
                h(Smile, { ...iconBaseStyle, color: '#facc15' }),
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
