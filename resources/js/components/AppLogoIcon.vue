<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" :class="className" v-bind="$attrs">
        <defs>
            <linearGradient id="blockGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" style="stop-color:#2563EB;stop-opacity:1" />
                <stop offset="100%" style="stop-color:#06B6D4;stop-opacity:1" />
            </linearGradient>
            <filter id="playShadow" x="-50%" y="-50%" width="200%" height="200%">
                <feDropShadow dx="0" dy="4" stdDeviation="6" flood-color="#000" flood-opacity="0.2"/>
            </filter>
        </defs>
        <g transform="translate(0, 16)">
            <rect x="60" y="40" width="110" height="140" rx="12" fill="url(#blockGrad)" opacity="0.5"/>
            <rect x="60" y="200" width="110" height="90" rx="12" fill="url(#blockGrad)" opacity="0.3"/>
            <rect x="60" y="310" width="110" height="140" rx="12" fill="url(#blockGrad)" opacity="0.6"/>
            <rect x="190" y="0" width="132" height="120" rx="12" fill="url(#blockGrad)" opacity="0.4"/>
            <rect x="190" y="140" width="132" height="200" rx="16" fill="url(#blockGrad)" opacity="1.0"/>
            <rect x="190" y="360" width="132" height="110" rx="12" fill="url(#blockGrad)" opacity="0.4"/>
            <rect x="342" y="60" width="110" height="180" rx="12" fill="url(#blockGrad)" opacity="0.6"/>
            <rect x="342" y="260" width="110" height="100" rx="12" fill="url(#blockGrad)" opacity="0.3"/>
            <rect x="342" y="380" width="110" height="80" rx="12" fill="url(#blockGrad)" opacity="0.5"/>
        </g>
        <g filter="url(#playShadow)">
            <circle cx="256" cy="256" r="128" stroke="#F59E0B" stroke-width="12" fill="none"/>
            <path d="M206 166 L356 256 L206 346 Z" fill="#F59E0B"/>
        </g>
    </svg>
</template>
