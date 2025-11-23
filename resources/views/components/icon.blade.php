@props(['name', 'class' => 'w-5 h-5', 'size' => 24])

@php
    // Map of icon names to Lucide icon component names
    $icons = [
        'check' => 'Check',
        'x' => 'X',
        'chevron-right' => 'ChevronRight',
        'chevron-left' => 'ChevronLeft',
        'arrow-right' => 'ArrowRight',
        'arrow-left' => 'ArrowLeft',
        'search' => 'Search',
        'menu' => 'Menu',
        'user' => 'User',
        'settings' => 'Settings',
        'home' => 'Home',
        'eye' => 'Eye',
        'eye-off' => 'EyeOff',
        'plus' => 'Plus',
        'minus' => 'Minus',
        'edit' => 'Edit',
        'trash' => 'Trash2',
        'more-vertical' => 'MoreVertical',
        'download' => 'Download',
        'upload' => 'Upload',
        'filter' => 'Filter',
        'calendar' => 'Calendar',
        'clock' => 'Clock',
        'star' => 'Star',
        'heart' => 'Heart',
        'share' => 'Share2',
        'copy' => 'Copy',
        'external-link' => 'ExternalLink',
    ];
    
    $iconName = $icons[$name] ?? 'X';
@endphp

<span 
    class="icon-lucide {{ $class }}" 
    data-icon="{{ $iconName }}"
    data-size="{{ $size }}"
></span>
