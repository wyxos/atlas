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

const iconMap = {
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
function initIcons() {
    const iconElements = document.querySelectorAll('.icon-lucide[data-icon]');
    
    iconElements.forEach((element) => {
        const iconName = element.getAttribute('data-icon');
        const size = parseInt(element.getAttribute('data-size') || '24');
        const iconClass = element.className.replace('icon-lucide', '').trim();
        
        if (iconMap[iconName]) {
            const IconComponent = iconMap[iconName];
            const icon = IconComponent({
                size,
                class: iconClass,
                strokeWidth: 2,
            });
            
            // Replace the span with the icon SVG
            if (icon) {
                element.outerHTML = icon.outerHTML || icon;
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
if (typeof window !== 'undefined') {
    window.initLucideIcons = initIcons;
}
