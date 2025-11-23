// Initialize Lucide icons for Blade components
import {
    Check,
    X,
    ChevronRight,
    ChevronLeft,
    ArrowRight,
    ArrowLeft,
    Search,
    Menu,
    User,
    Settings,
    Home,
    Eye,
    EyeOff,
    Plus,
    Minus,
    Edit,
    Trash2,
    MoreVertical,
    Download,
    Upload,
    Filter,
    Calendar,
    Clock,
    Star,
    Heart,
    Share2,
    Copy,
    ExternalLink,
} from 'lucide';

type IconName =
    | 'Check'
    | 'X'
    | 'ChevronRight'
    | 'ChevronLeft'
    | 'ArrowRight'
    | 'ArrowLeft'
    | 'Search'
    | 'Menu'
    | 'User'
    | 'Settings'
    | 'Home'
    | 'Eye'
    | 'EyeOff'
    | 'Plus'
    | 'Minus'
    | 'Edit'
    | 'Trash2'
    | 'MoreVertical'
    | 'Download'
    | 'Upload'
    | 'Filter'
    | 'Calendar'
    | 'Clock'
    | 'Star'
    | 'Heart'
    | 'Share2'
    | 'Copy'
    | 'ExternalLink';

// Lucide icons are functions that return icon nodes
// Using any for icon map since lucide types are complex
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const iconMap: Record<IconName, any> = {
    Check,
    X,
    ChevronRight,
    ChevronLeft,
    ArrowRight,
    ArrowLeft,
    Search,
    Menu,
    User,
    Settings,
    Home,
    Eye,
    EyeOff,
    Plus,
    Minus,
    Edit,
    Trash2,
    MoreVertical,
    Download,
    Upload,
    Filter,
    Calendar,
    Clock,
    Star,
    Heart,
    Share2,
    Copy,
    ExternalLink,
};

// Initialize icons when DOM is ready
function initIcons(): void {
    const iconElements = document.querySelectorAll<HTMLElement>('.icon-lucide[data-icon]');

    iconElements.forEach((element) => {
        const iconName = element.getAttribute('data-icon') as IconName | null;
        const sizeAttr = element.getAttribute('data-size');
        const size = sizeAttr ? parseInt(sizeAttr, 10) : 24;
        const iconClass = element.className.replace('icon-lucide', '').trim();

        if (iconName && iconMap[iconName]) {
            const IconComponent = iconMap[iconName];
            const icon = IconComponent({
                size,
                class: iconClass,
                strokeWidth: 2,
            });

            // Replace the span with the icon SVG
            if (icon) {
                if (icon instanceof SVGElement) {
                    element.outerHTML = icon.outerHTML;
                } else if (typeof icon === 'string') {
                    element.outerHTML = icon;
                }
            }
        }
    });
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initIcons);
} else {
    initIcons();
}

// Also initialize after dynamic content loads
declare global {
    interface Window {
        initLucideIcons?: () => void;
    }
}

if (typeof window !== 'undefined') {
    window.initLucideIcons = initIcons;
}

