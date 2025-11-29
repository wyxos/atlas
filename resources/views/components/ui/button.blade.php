@props([
    'variant' => 'default',
    'size' => 'default',
    'loading' => false,
    'as' => 'button',
])

@php
    // Base classes (keep in sync with resources/js/components/ui/button/index.ts)
    $base = 'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 shrink-0 outline-none focus-visible:ring-2 focus-visible:ring-smart-blue-400/50';

    $variants = [
        'default' => 'bg-smart-blue-500 text-white hover:bg-smart-blue-600',
        'destructive' => 'bg-danger-500 text-white hover:bg-danger-600',
        'outline' => 'border border-twilight-indigo-500 bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100',
        'secondary' => 'bg-sapphire-500 text-white hover:bg-sapphire-600',
        'ghost' => 'text-twilight-indigo-100 hover:bg-smart-blue-700 hover:text-smart-blue-100',
        'link' => 'text-smart-blue-400 underline-offset-4 hover:underline hover:text-smart-blue-300',
    ];

    $sizes = [
        'default' => 'h-9 px-4 py-2',
        'sm' => 'h-8 rounded-md gap-1.5 px-3',
        'lg' => 'h-10 rounded-md px-6',
        'icon' => 'size-9',
        'icon-sm' => 'size-8',
        'icon-lg' => 'size-10',
    ];

    $computed = trim($base . ' ' . ($variants[$variant] ?? $variants['default']) . ' ' . ($sizes[$size] ?? $sizes['default']));
    $isDisabled = $loading || $attributes->get('disabled', false);
@endphp

@if ($as === 'a')
    <a {{ $attributes->class("$computed relative") }}>
        <span class="{{ $loading ? 'invisible' : '' }}">
            {{ $slot }}
        </span>
        @if ($loading)
            <span class="absolute inset-0 flex items-center justify-center">
                <svg class="animate-spin" width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4A4 4 0 008 12H4z"></path>
                </svg>
            </span>
        @endif
    </a>
@else
    <button {{ $attributes->class("$computed relative")->merge(['type' => 'button']) }} @disabled($isDisabled)>
        <span class="{{ $loading ? 'invisible' : '' }}">
            {{ $slot }}
        </span>
        @if ($loading)
            <span class="absolute inset-0 flex items-center justify-center">
                <svg class="animate-spin" width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4A4 4 0 008 12H4z"></path>
                </svg>
            </span>
        @endif
    </button>
@endif

