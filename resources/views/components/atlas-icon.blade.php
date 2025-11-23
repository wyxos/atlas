@props(['class' => 'w-32 h-32'])

@php
    $svgContent = file_get_contents(resource_path('svg/atlas-icon.svg'));
    $svgContent = str_replace('<svg', '<svg class="' . $class . '"', $svgContent);
@endphp

{!! $svgContent !!}


