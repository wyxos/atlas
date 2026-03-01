import { computed, createApp, defineComponent, h, onUnmounted, ref } from 'vue';
import type { ProgressEvent } from './download-progress-bus';
import {
    clearAtlasRequestLog,
    getAtlasRequestLogSnapshot,
    subscribeToAtlasRequestLog,
    type AtlasRequestLogEntry,
} from './atlas-request-log';

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

type RequestLogRow = AtlasRequestLogEntry & {
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
        const eventRows = ref<EventLogRow[]>([]);
        const requestRows = ref<RequestLogRow[]>(
            getAtlasRequestLogSnapshot().map((row) => ({
                ...row,
                expanded: false,
            })),
        );
        let nextId = 1;

        function push(event: ProgressEvent): void {
            const createdAt = new Date().toLocaleTimeString();
            eventRows.value = [
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
                ...eventRows.value,
            ].slice(0, MAX_ROWS);
        }

        function toggleEventRow(id: number): void {
            eventRows.value = eventRows.value.map((row) => row.id === id ? { ...row, expanded: !row.expanded } : row);
        }

        function toggleRequestRow(id: number): void {
            requestRows.value = requestRows.value.map((row) => row.id === id ? { ...row, expanded: !row.expanded } : row);
        }

        function clearEventRows(): void {
            eventRows.value = [];
        }

        function clearRequestRows(): void {
            clearAtlasRequestLog();
        }

        function mergeRequestRows(entries: AtlasRequestLogEntry[]): void {
            const expandedById = new Map(requestRows.value.map((row) => [row.id, row.expanded]));
            requestRows.value = entries.map((row) => ({
                ...row,
                expanded: expandedById.get(row.id) ?? false,
            }));
        }

        const eventCount = computed(() => eventRows.value.length);
        const requestCount = computed(() => requestRows.value.length);

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

        const unsubscribe = subscribeToAtlasRequestLog((entries) => {
            mergeRequestRows(entries);
        });

        onUnmounted(() => {
            unsubscribe();
        });

        return {
            isOpen,
            eventRows,
            requestRows,
            eventCount,
            requestCount,
            push,
            toggleEventRow,
            toggleRequestRow,
            clearEventRows,
            clearRequestRows,
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
                            top: '4vh',
                            right: '2vw',
                            width: '1200px',
                            maxWidth: '96vw',
                            height: '92vh',
                            background: '#020617',
                            color: '#e2e8f0',
                            border: '1px solid rgba(148,163,184,0.25)',
                            borderRadius: '14px',
                            boxShadow: '0 20px 60px rgba(0,0,0,0.55)',
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
                                }, 'Download Debug Sheet'),
                                h('div', {
                                    style: { fontSize: '12px', color: '#94a3b8', marginTop: '2px' },
                                }, `Alt + A · Reverb ${this.eventCount} · Atlas ${this.requestCount}`),
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
                                    onClick: this.clearEventRows,
                                }, 'Clear Events'),
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
                                    onClick: this.clearRequestRows,
                                }, 'Clear Requests'),
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
                            },
                        }, [
                            h('div', {
                                style: {
                                    display: 'grid',
                                    gap: '12px',
                                    gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))',
                                },
                            }, [
                                h('section', {
                                    style: {
                                        minHeight: '0',
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: '8px',
                                    },
                                }, [
                                    h('h3', {
                                        style: {
                                            margin: '0 0 4px',
                                            fontSize: '13px',
                                            color: '#94a3b8',
                                            letterSpacing: '0.02em',
                                        },
                                    }, 'Reverb Events'),
                                    ...this.eventRows.map((row) => h('article', {
                                        key: `event-${row.id}`,
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
                                                this.toggleEventRow(row.id);
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
                                            ? h('div', {
                                                style: {
                                                    borderTop: '1px solid rgba(148,163,184,0.2)',
                                                    display: 'grid',
                                                    gap: '8px',
                                                    padding: '10px 12px 12px',
                                                },
                                            }, [
                                                h('div', { style: { fontSize: '11px', color: '#94a3b8', fontWeight: '700' } }, 'Payload'),
                                                h('pre', {
                                                    style: {
                                                        margin: '0',
                                                        fontSize: '12px',
                                                        lineHeight: '1.45',
                                                        overflowX: 'auto',
                                                        whiteSpace: 'pre',
                                                        color: '#e2e8f0',
                                                    },
                                                    innerHTML: toHighlightedJson(row.payload),
                                                }),
                                                h('div', { style: { fontSize: '11px', color: '#94a3b8', fontWeight: '700' } }, 'Response'),
                                                h('pre', {
                                                    style: {
                                                        margin: '0',
                                                        fontSize: '12px',
                                                        lineHeight: '1.45',
                                                        overflowX: 'auto',
                                                        whiteSpace: 'pre',
                                                        color: '#e2e8f0',
                                                    },
                                                    innerHTML: toHighlightedJson(null),
                                                }),
                                            ])
                                            : null,
                                    ])),
                                ]),
                                h('section', {
                                    style: {
                                        minHeight: '0',
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: '8px',
                                    },
                                }, [
                                    h('h3', {
                                        style: {
                                            margin: '0 0 4px',
                                            fontSize: '13px',
                                            color: '#94a3b8',
                                            letterSpacing: '0.02em',
                                        },
                                    }, 'Atlas Requests'),
                                    ...this.requestRows.map((row) => h('article', {
                                        key: `request-${row.id}`,
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
                                                this.toggleRequestRow(row.id);
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
                                                    h('strong', { style: { color: '#f8fafc' } }, `${row.method} ${row.endpoint}`),
                                                    h('span', { style: { color: '#94a3b8' } }, row.timestamp),
                                                ]),
                                                h('div', `status=${String(row.status)} duration=${row.durationMs}ms`),
                                            ]),
                                        ]),
                                        row.expanded
                                            ? h('div', {
                                                style: {
                                                    borderTop: '1px solid rgba(148,163,184,0.2)',
                                                    display: 'grid',
                                                    gap: '8px',
                                                    padding: '10px 12px 12px',
                                                },
                                            }, [
                                                h('div', { style: { fontSize: '11px', color: '#94a3b8', fontWeight: '700' } }, 'Payload'),
                                                h('pre', {
                                                    style: {
                                                        margin: '0',
                                                        fontSize: '12px',
                                                        lineHeight: '1.45',
                                                        overflowX: 'auto',
                                                        whiteSpace: 'pre',
                                                        color: '#e2e8f0',
                                                    },
                                                    innerHTML: toHighlightedJson(row.requestPayload),
                                                }),
                                                h('div', { style: { fontSize: '11px', color: '#94a3b8', fontWeight: '700' } }, 'Response'),
                                                h('pre', {
                                                    style: {
                                                        margin: '0',
                                                        fontSize: '12px',
                                                        lineHeight: '1.45',
                                                        overflowX: 'auto',
                                                        whiteSpace: 'pre',
                                                        color: '#e2e8f0',
                                                    },
                                                    innerHTML: toHighlightedJson(row.responsePayload),
                                                }),
                                            ])
                                            : null,
                                    ])),
                                ]),
                            ]),
                        ]),
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
