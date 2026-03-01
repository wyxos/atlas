import { computed, createApp, defineComponent, h, ref } from 'vue';
import type { ProgressEvent } from './download-progress-bus';

type EventLogRow = {
    id: number;
    createdAt: string;
    event: ProgressEvent['event'];
    transferId: number | null;
    fileId: number | null;
    status: string | null;
    percent: number | null;
    payload: Record<string, unknown>;
    expanded: boolean;
};

type DownloadEventSheetApi = {
    push: (event: ProgressEvent) => void;
};

const MAX_ROWS = 200;

function escapeHtml(input: string): string {
    return input
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function toHighlightedJson(value: unknown): string {
    const json = JSON.stringify(value, null, 2) ?? '';
    const escaped = escapeHtml(json);

    return escaped.replace(
        /("(?:\\u[a-fA-F0-9]{4}|\\[^u]|[^\\"])*"(?=\s*:)?|\btrue\b|\bfalse\b|\bnull\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/g,
        (match) => {
            if (match.startsWith('"') && match.endsWith('"')) {
                const nextChar = escaped[escaped.indexOf(match) + match.length];
                const isKey = nextChar === ':';
                return `<span style="color:${isKey ? '#93c5fd' : '#86efac'}">${match}</span>`;
            }

            if (match === 'true' || match === 'false') {
                return '<span style="color:#fca5a5">' + match + '</span>';
            }

            if (match === 'null') {
                return '<span style="color:#c4b5fd">null</span>';
            }

            return '<span style="color:#fcd34d">' + match + '</span>';
        },
    );
}

const DownloadEventSheet = defineComponent({
    name: 'DownloadEventSheet',
    setup() {
        const isOpen = ref(false);
        const rows = ref<EventLogRow[]>([]);
        let nextId = 1;

        function push(event: ProgressEvent): void {
            const createdAt = new Date().toLocaleTimeString();
            rows.value = [
                {
                    id: nextId++,
                    createdAt,
                    event: event.event,
                    transferId: event.transferId,
                    fileId: event.fileId,
                    status: event.status,
                    percent: event.percent,
                    payload: event.payload,
                    expanded: false,
                },
                ...rows.value,
            ].slice(0, MAX_ROWS);
        }

        function toggleRow(id: number): void {
            rows.value = rows.value.map((row) => row.id === id ? { ...row, expanded: !row.expanded } : row);
        }

        function clearRows(): void {
            rows.value = [];
        }

        const count = computed(() => rows.value.length);

        window.addEventListener('keydown', (event) => {
            if (!event.altKey || event.key.toLowerCase() !== 'a') {
                return;
            }

            const target = event.target as HTMLElement | null;
            if (target && target.closest('input, textarea, select, [contenteditable="true"]')) {
                return;
            }

            event.preventDefault();
            isOpen.value = !isOpen.value;
        }, true);

        return {
            isOpen,
            rows,
            count,
            push,
            toggleRow,
            clearRows,
        };
    },
    render() {
        if (!this.isOpen) {
            return null;
        }

        return h(
            'div',
            {
                style: {
                    position: 'fixed',
                    inset: '0',
                    zIndex: '2147483647',
                    pointerEvents: 'none',
                },
            },
            [
                h('div', {
                    style: {
                        position: 'absolute',
                        inset: '0',
                        background: 'rgba(0,0,0,0.35)',
                        pointerEvents: 'auto',
                    },
                    onClick: () => {
                        this.isOpen = false;
                    },
                }),
                h(
                    'aside',
                    {
                        style: {
                            position: 'absolute',
                            top: '0',
                            right: '0',
                            width: '560px',
                            maxWidth: '92vw',
                            height: '100vh',
                            background: '#020617',
                            color: '#e2e8f0',
                            borderLeft: '1px solid rgba(148,163,184,0.25)',
                            boxShadow: '-20px 0 40px rgba(0,0,0,0.45)',
                            display: 'flex',
                            flexDirection: 'column',
                            pointerEvents: 'auto',
                        },
                    },
                    [
                        h('header', {
                            style: {
                                padding: '14px 16px',
                                borderBottom: '1px solid rgba(148,163,184,0.25)',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                gap: '8px',
                            },
                        }, [
                            h('div', [
                                h('div', {
                                    style: { fontSize: '15px', fontWeight: '700', color: '#f8fafc' },
                                }, 'Download Events'),
                                h('div', {
                                    style: { fontSize: '12px', color: '#94a3b8', marginTop: '2px' },
                                }, `Alt + A · ${this.count} event(s)`),
                            ]),
                            h('div', {
                                style: { display: 'flex', gap: '8px' },
                            }, [
                                h('button', {
                                    type: 'button',
                                    style: {
                                        border: '1px solid rgba(148,163,184,0.35)',
                                        color: '#e2e8f0',
                                        background: 'transparent',
                                        padding: '6px 10px',
                                        borderRadius: '8px',
                                        cursor: 'pointer',
                                        fontSize: '12px',
                                    },
                                    onClick: this.clearRows,
                                }, 'Clear'),
                                h('button', {
                                    type: 'button',
                                    style: {
                                        border: '1px solid rgba(148,163,184,0.35)',
                                        color: '#e2e8f0',
                                        background: 'transparent',
                                        padding: '6px 10px',
                                        borderRadius: '8px',
                                        cursor: 'pointer',
                                        fontSize: '12px',
                                    },
                                    onClick: () => {
                                        this.isOpen = false;
                                    },
                                }, 'Close'),
                            ]),
                        ]),
                        h('div', {
                            style: {
                                padding: '10px',
                                overflow: 'auto',
                                flex: '1',
                                display: 'flex',
                                flexDirection: 'column',
                                gap: '8px',
                            },
                        }, this.rows.map((row) => h('article', {
                            key: row.id,
                            style: {
                                border: '1px solid rgba(148,163,184,0.25)',
                                borderRadius: '10px',
                                overflow: 'hidden',
                                background: 'rgba(15,23,42,0.85)',
                            },
                        }, [
                            h('button', {
                                type: 'button',
                                style: {
                                    all: 'unset',
                                    cursor: 'pointer',
                                    display: 'block',
                                    width: '100%',
                                    padding: '10px 12px',
                                },
                                onClick: () => {
                                    this.toggleRow(row.id);
                                },
                            }, [
                                h('div', {
                                    style: {
                                        display: 'grid',
                                        gap: '2px',
                                        fontSize: '12px',
                                        color: '#cbd5e1',
                                    },
                                }, [
                                    h('div', {
                                        style: {
                                            display: 'flex',
                                            justifyContent: 'space-between',
                                            alignItems: 'center',
                                            gap: '8px',
                                        },
                                    }, [
                                        h('strong', { style: { color: '#f8fafc' } }, row.event),
                                        h('span', { style: { color: '#94a3b8' } }, row.createdAt),
                                    ]),
                                    h('div', `transfer=${row.transferId ?? '-'} file=${row.fileId ?? '-'} status=${row.status ?? '-'} percent=${row.percent ?? '-'}`),
                                ]),
                            ]),
                            row.expanded
                                ? h('pre', {
                                    style: {
                                        margin: '0',
                                        padding: '10px 12px 12px',
                                        borderTop: '1px solid rgba(148,163,184,0.2)',
                                        fontSize: '12px',
                                        lineHeight: '1.45',
                                        overflowX: 'auto',
                                        whiteSpace: 'pre',
                                        color: '#e2e8f0',
                                    },
                                    innerHTML: toHighlightedJson(row.payload),
                                })
                                : null,
                        ]))),
                    ],
                ),
            ],
        );
    },
});

export function createDownloadEventSheet(): DownloadEventSheetApi {
    const mountNode = document.createElement('div');
    mountNode.setAttribute('data-atlas-download-events-sheet', '1');
    document.documentElement.appendChild(mountNode);

    const app = createApp(DownloadEventSheet);
    const instance = app.mount(mountNode) as unknown as {
        push: (event: ProgressEvent) => void;
    };

    return {
        push: (event) => {
            instance.push(event);
        },
    };
}
