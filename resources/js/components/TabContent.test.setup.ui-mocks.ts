import { vi } from 'vitest';

vi.mock('./FileViewer.vue', () => ({
    default: {
        name: 'FileViewer',
        template: '<div class="file-viewer-mock"></div>',
        props: ['containerRef', 'masonryContainerRef', 'items', 'hasMore', 'isLoading', 'onLoadMore', 'onReaction', 'masonry', 'tabId'],
        emits: ['close', 'preview-failure'],
        methods: {
            openFromClick: vi.fn(),
            close: vi.fn(),
        },
        expose: ['openFromClick', 'close'],
    },
}));

// Mock ContainerBlacklistManager
vi.mock('./container-blacklist/ContainerBlacklistManager.vue', () => ({
    default: {
        name: 'ContainerBlacklistManager',
        template: '<div class="container-blacklist-manager-mock"></div>',
        props: ['disabled'],
        emits: ['blacklists-changed'],
        methods: {
            openBlacklistDialog: vi.fn(),
        },
        expose: ['openBlacklistDialog'],
    },
}));

// Mock ContainerBlacklistDialog
vi.mock('./container-blacklist/ContainerBlacklistDialog.vue', () => ({
    default: {
        name: 'ContainerBlacklistDialog',
        template: '<div class="container-blacklist-dialog-mock"></div>',
        props: ['open', 'containerId', 'containerType', 'containerSource', 'containerSourceId', 'containerReferrer'],
        emits: ['update:open', 'blacklist-changed'],
    },
}));

// Mock container blacklist composables
vi.mock('@/composables/useContainerBlacklists', async () => {
    const { ref } = await import('vue');
    return {
        useContainerBlacklists: vi.fn(() => ({
            blacklists: ref([]),
            isLoading: ref(false),
            error: ref(null),
            fetchBlacklists: vi.fn(),
            createBlacklist: vi.fn(),
            deleteBlacklist: vi.fn(),
            isContainerBlacklisted: vi.fn(() => false),
            getBlacklistedContainerActionType: vi.fn(() => null),
        })),
    };
});

// Mock BrowseStatusBar
vi.mock('./BrowseStatusBar.vue', () => ({
    default: {
        name: 'BrowseStatusBar',
        template: '<div class="browse-status-bar-mock"></div>',
        props: ['items', 'masonry', 'tab', 'nextCursor', 'isLoading', 'visible', 'total'],
    },
}));

// Mock FileReactions
vi.mock('./FileReactions.vue', () => ({
    default: {
        name: 'FileReactions',
        template: '<div class="file-reactions-mock"></div>',
        props: ['fileId', 'previewedCount', 'viewedCount', 'currentIndex', 'totalItems', 'variant', 'removeItem'],
        emits: ['reaction'],
    },
}));

// Mock UI components
vi.mock('./ui/button', () => ({
    Button: {
        name: 'Button',
        template: '<button v-bind="$attrs" :disabled="disabled"><slot></slot></button>',
        props: ['variant', 'size', 'disabled', 'color', 'loading'],
    },
}));

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenu: {
        name: 'DropdownMenu',
        template: '<div class="dropdown-menu-mock"><slot></slot></div>',
    },
    DropdownMenuTrigger: {
        name: 'DropdownMenuTrigger',
        template: '<div class="dropdown-menu-trigger-mock"><slot></slot></div>',
        props: ['asChild'],
    },
    DropdownMenuContent: {
        name: 'DropdownMenuContent',
        template: '<div class="dropdown-menu-content-mock"><slot></slot></div>',
        props: ['align', 'class'],
    },
    DropdownMenuLabel: {
        name: 'DropdownMenuLabel',
        template: '<div class="dropdown-menu-label-mock"><slot></slot></div>',
        props: ['class'],
    },
    DropdownMenuSeparator: {
        name: 'DropdownMenuSeparator',
        template: '<div class="dropdown-menu-separator-mock"></div>',
        props: ['class'],
    },
    DropdownMenuItem: {
        name: 'DropdownMenuItem',
        template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'select\')"><slot></slot></button>',
        props: ['disabled', 'class'],
        emits: ['select'],
    },
}));

vi.mock('./ui/Pill.vue', () => ({
    default: {
        name: 'Pill',
            template: '<span class="pill-mock"><span class="pill-label">{{ label }}</span><span class="pill-value">{{ value }}</span><button v-if="dismissible" @click.stop="$emit(\'dismiss\')" class="pill-dismiss-button">×</button></span>',
        props: ['label', 'value', 'variant', 'size', 'reversed', 'dismissible'],
            emits: ['dismiss'],
    },
}));

vi.mock('./ui/select', () => ({
    Select: {
        name: 'Select',
        template: '<div class="select-mock"><slot></slot></div>',
        props: ['modelValue', 'disabled'],
        emits: ['update:modelValue'],
    },
    SelectContent: {
        name: 'SelectContent',
        template: '<div class="select-content-mock"><slot></slot></div>',
    },
    SelectItem: {
        name: 'SelectItem',
        template: '<div class="select-item-mock"><slot></slot></div>',
        props: ['value'],
    },
    SelectTrigger: {
        name: 'SelectTrigger',
        template: '<div class="select-trigger-mock"><slot></slot></div>',
        props: ['class'],
    },
    SelectValue: {
        name: 'SelectValue',
        template: '<div class="select-value-mock"><slot></slot></div>',
        props: ['placeholder'],
    },
}));

vi.mock('./ui/sheet', () => ({
    Sheet: {
        name: 'Sheet',
        template: '<div class="sheet-mock"><slot></slot></div>',
        props: ['modelValue', 'open', 'modal'],
        emits: ['update:modelValue', 'update:open'],
    },
    SheetContent: {
        name: 'SheetContent',
        template: '<div class="sheet-content-mock"><slot></slot></div>',
        props: ['side', 'class'],
    },
    SheetHeader: {
        name: 'SheetHeader',
        template: '<div class="sheet-header-mock"><slot></slot></div>',
    },
    SheetTitle: {
        name: 'SheetTitle',
        template: '<div class="sheet-title-mock"><slot></slot></div>',
    },
    SheetDescription: {
        name: 'SheetDescription',
        template: '<div class="sheet-description-mock"><slot></slot></div>',
        props: ['class'],
    },
    SheetTrigger: {
        name: 'SheetTrigger',
        template: '<div class="sheet-trigger-mock" @click="$emit(\'update:open\', true)"><slot></slot></div>',
        props: ['asChild'],
        emits: ['update:open'],
    },
    SheetFooter: {
        name: 'SheetFooter',
        template: '<div class="sheet-footer-mock"><slot></slot></div>',
    },
}));

vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        name: 'Sheet',
        template: '<div class="sheet-mock"><slot></slot></div>',
        props: ['modelValue', 'open', 'modal'],
        emits: ['update:modelValue', 'update:open'],
    },
    SheetContent: {
        name: 'SheetContent',
        template: '<div class="sheet-content-mock"><slot></slot></div>',
        props: ['side', 'class'],
    },
    SheetHeader: {
        name: 'SheetHeader',
        template: '<div class="sheet-header-mock"><slot></slot></div>',
    },
    SheetTitle: {
        name: 'SheetTitle',
        template: '<div class="sheet-title-mock"><slot></slot></div>',
    },
    SheetDescription: {
        name: 'SheetDescription',
        template: '<div class="sheet-description-mock"><slot></slot></div>',
        props: ['class'],
    },
    SheetTrigger: {
        name: 'SheetTrigger',
        template: '<div class="sheet-trigger-mock" @click="$emit(\'update:open\', true)"><slot></slot></div>',
        props: ['asChild'],
        emits: ['update:open'],
    },
    SheetFooter: {
        name: 'SheetFooter',
        template: '<div class="sheet-footer-mock"><slot></slot></div>',
    },
}));

vi.mock('@/components/ui/carousel', () => ({
    Carousel: {
        name: 'Carousel',
        template: '<div v-bind="$attrs" :class="$props.class"><slot></slot></div>',
        props: ['opts', 'plugins', 'orientation', 'class'],
    },
    CarouselContent: {
        name: 'CarouselContent',
        template: '<div v-bind="$attrs"><div :class="$props.class"><slot></slot></div></div>',
        props: ['class'],
    },
    CarouselItem: {
        name: 'CarouselItem',
        template: '<div v-bind="$attrs" :class="$props.class"><slot></slot></div>',
        props: ['class'],
    },
    CarouselPrevious: {
        name: 'CarouselPrevious',
        template: '<button type="button" v-bind="$attrs" :class="$props.class"><slot></slot></button>',
        props: ['class', 'variant', 'size'],
    },
    CarouselNext: {
        name: 'CarouselNext',
        template: '<button type="button" v-bind="$attrs" :class="$props.class"><slot></slot></button>',
        props: ['class', 'variant', 'size'],
    },
}));

vi.mock('./ui/switch', () => ({
    Switch: {
        name: 'Switch',
        template: '<div class="switch-mock"><slot></slot></div>',
        props: ['modelValue'],
        emits: ['update:modelValue'],
    },
}));

vi.mock('./ui/radio-group', () => ({
    RadioGroup: {
        name: 'RadioGroup',
        template: '<div class="radio-group-mock"><slot></slot></div>',
        props: ['modelValue'],
        emits: ['update:modelValue'],
    },
    RadioGroupItem: {
        name: 'RadioGroupItem',
        template: '<div class="radio-group-item-mock"><slot></slot></div>',
        props: ['value', 'id'],
    },
}));

vi.mock('./ui/dialog', () => ({
    Dialog: {
        name: 'Dialog',
        template: '<div class="dialog-mock"><slot></slot></div>',
        props: ['modelValue'],
        emits: ['update:modelValue'],
    },
    DialogContent: {
        name: 'DialogContent',
        template: '<div class="dialog-content-mock"><slot></slot></div>',
        props: ['class'],
    },
    DialogDescription: {
        name: 'DialogDescription',
        template: '<div class="dialog-description-mock"><slot></slot></div>',
        props: ['class'],
    },
    DialogFooter: {
        name: 'DialogFooter',
        template: '<div class="dialog-footer-mock"><slot></slot></div>',
    },
    DialogHeader: {
        name: 'DialogHeader',
        template: '<div class="dialog-header-mock"><slot></slot></div>',
    },
    DialogTitle: {
        name: 'DialogTitle',
        template: '<div class="dialog-title-mock"><slot></slot></div>',
        props: ['class'],
    },
    DialogClose: {
        name: 'DialogClose',
        template: '<div class="dialog-close-mock"><slot></slot></div>',
        props: ['asChild'],
    },
}));

// Mock icons
vi.mock('lucide-vue-next', () => ({
    Loader2: { name: 'Loader2', template: '<div class="loader-icon"></div>', props: ['size', 'class'] },
    AlertTriangle: { name: 'AlertTriangle', template: '<div class="alert-icon"></div>', props: ['size'] },
    Info: { name: 'Info', template: '<div class="info-icon"></div>', props: ['size'] },
    Copy: { name: 'Copy', template: '<div class="copy-icon"></div>', props: ['size', 'class'] },
    ChevronsLeft: { name: 'ChevronsLeft', template: '<div class="chevrons-left-icon"></div>', props: ['size'] },
    ChevronsUp: { name: 'ChevronsUp', template: '<div class="chevrons-up-icon"></div>', props: ['size', 'class'] },
    SlidersHorizontal: { name: 'SlidersHorizontal', template: '<div class="sliders-icon"></div>', props: ['size'] },
    X: { name: 'X', template: '<div class="x-icon"></div>', props: ['size', 'class'] },
    Check: { name: 'Check', template: '<div class="check-icon"></div>', props: ['size', 'class'] },
    ChevronDown: { name: 'ChevronDown', template: '<div class="chevron-down-icon"></div>', props: ['size', 'class'] },
    Play: { name: 'Play', template: '<div class="play-icon"></div>', props: ['size', 'class'] },
    RotateCcw: { name: 'RotateCcw', template: '<div class="rotate-ccw-icon"></div>', props: ['size', 'class'] },
    RotateCw: { name: 'RotateCw', template: '<div class="rotate-cw-icon"></div>', props: ['size', 'class'] },
    ThumbsDown: { name: 'ThumbsDown', template: '<div class="thumbs-down-icon"></div>', props: ['size', 'class'] },
    Pause: { name: 'Pause', template: '<div class="pause-icon"></div>', props: ['size', 'class'] },
    ChevronLeft: { name: 'ChevronLeft', template: '<div class="chevron-left-icon"></div>', props: ['size', 'class'] },
    Shield: { name: 'Shield', template: '<div class="shield-icon"></div>', props: ['size', 'class'] },
    Plus: { name: 'Plus', template: '<div class="plus-icon"></div>', props: ['size', 'class'] },
    Trash2: { name: 'Trash2', template: '<div class="trash-icon"></div>', props: ['size', 'class'] },
    GripVertical: { name: 'GripVertical', template: '<div class="grip-icon"></div>', props: ['size', 'class'] },
    ChevronRight: { name: 'ChevronRight', template: '<div class="chevron-right-icon"></div>', props: ['size', 'class'] },
    Save: { name: 'Save', template: '<div class="save-icon"></div>', props: ['size', 'class'] },
    Ban: { name: 'Ban', template: '<div class="ban-icon"></div>', props: ['size', 'class'] },
}));

// Mock composables
