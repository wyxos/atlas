import { h, type VNode } from 'vue';
import { ExternalLink, Loader2, Trash2 } from 'lucide-vue-next';

type BadgeFileActionModel = {
    atlasFileUrl: string | null;
    canDeleteFile: boolean;
    controlsDisabled: boolean;
    deletingFile: boolean;
};

type BadgeFileActionHandlers = {
    onDeleteFileClick: () => void;
};

const fileActionIconStyle = {
    width: '18px',
    height: '18px',
    strokeWidth: 2,
    color: '#ffffff',
} as const;

const fileActionButtonStyle = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: '4px',
    padding: '4px',
    cursor: 'pointer',
    border: 'none',
    background: 'transparent',
    transition: 'background-color 160ms ease, color 160ms ease, opacity 160ms ease',
} as const;

export function renderAtlasFileLink(model: BadgeFileActionModel): VNode | null {
    if (model.atlasFileUrl === null) {
        return null;
    }

    return h(
        'a',
        {
            href: model.atlasFileUrl,
            target: '_blank',
            rel: 'noopener noreferrer',
            'aria-label': 'Open file in Atlas',
            title: 'Open file in Atlas',
            style: {
                ...fileActionButtonStyle,
                textDecoration: 'none',
                opacity: 1,
            },
        },
        [
            h(ExternalLink, {
                ...fileActionIconStyle,
                color: '#ffffff',
            }),
        ],
    );
}

export function renderDeleteFileButton(model: BadgeFileActionModel, handlers: BadgeFileActionHandlers): VNode | null {
    if (!model.canDeleteFile) {
        return null;
    }

    const disabled = model.controlsDisabled || model.deletingFile;

    return h(
        'button',
        {
            type: 'button',
            disabled,
            'aria-label': 'Delete downloaded file from Atlas',
            title: 'Delete downloaded file from Atlas',
            onClick: handlers.onDeleteFileClick,
            style: {
                ...fileActionButtonStyle,
                opacity: disabled ? 0.75 : 1,
                cursor: disabled ? 'not-allowed' : 'pointer',
            },
        },
        [
            model.deletingFile
                ? h(Loader2, {
                    ...fileActionIconStyle,
                    style: {
                        animation: 'atlas-badge-spin 0.9s linear infinite',
                    },
                })
                : h(Trash2, {
                    ...fileActionIconStyle,
                    color: '#ffffff',
                }),
        ],
    );
}
