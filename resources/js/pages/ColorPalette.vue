<script setup lang="ts">
import { ref } from 'vue';
import { Check } from 'lucide-vue-next';
import GuidelinesPageLayout from '../components/GuidelinesPageLayout.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';
import Heading from '../components/ui/Heading.vue';

// Type definitions
type ShadeValue = {
    class: string;
    hex: string;
};

type ColorPalette = {
    name: string;
    slug: string;
    shades: {
        100: ShadeValue;
        200: ShadeValue;
        300: ShadeValue;
        400: ShadeValue;
        500: ShadeValue;
        600: ShadeValue;
        700: ShadeValue;
        800: ShadeValue;
        900: ShadeValue;
    };
};

// Color palette data with hex values
const colorPalettes: ColorPalette[] = [
    {
        name: 'Smart Blue',
        slug: 'smart-blue',
        shades: {
            100: { class: 'bg-smart-blue-100', hex: '#c3e0fe' },
            200: { class: 'bg-smart-blue-200', hex: '#87c2fd' },
            300: { class: 'bg-smart-blue-300', hex: '#4ba3fb' },
            400: { class: 'bg-smart-blue-400', hex: '#0f85fa' },
            500: { class: 'bg-smart-blue-500', hex: '#0466c8' },
            600: { class: 'bg-smart-blue-600', hex: '#0352a0' },
            700: { class: 'bg-smart-blue-700', hex: '#023d78' },
            800: { class: 'bg-smart-blue-800', hex: '#022950' },
            900: { class: 'bg-smart-blue-900', hex: '#011428' },
        },
    },
    {
        name: 'Sapphire',
        slug: 'sapphire',
        shades: {
            100: { class: 'bg-sapphire-100', hex: '#bcddfe' },
            200: { class: 'bg-sapphire-200', hex: '#79bbfc' },
            300: { class: 'bg-sapphire-300', hex: '#3698fb' },
            400: { class: 'bg-sapphire-400', hex: '#0576e8' },
            500: { class: 'bg-sapphire-500', hex: '#0353a4' },
            600: { class: 'bg-sapphire-600', hex: '#034384' },
            700: { class: 'bg-sapphire-700', hex: '#023263' },
            800: { class: 'bg-sapphire-800', hex: '#012242' },
            900: { class: 'bg-sapphire-900', hex: '#011121' },
        },
    },
    {
        name: 'Regal Navy',
        slug: 'regal-navy',
        shades: {
            100: { class: 'bg-regal-navy-100', hex: '#b4d8fe' },
            200: { class: 'bg-regal-navy-200', hex: '#68b0fd' },
            300: { class: 'bg-regal-navy-300', hex: '#1d89fc' },
            400: { class: 'bg-regal-navy-400', hex: '#0363c9' },
            500: { class: 'bg-regal-navy-500', hex: '#023e7d' },
            600: { class: 'bg-regal-navy-600', hex: '#023164' },
            700: { class: 'bg-regal-navy-700', hex: '#01254b' },
            800: { class: 'bg-regal-navy-800', hex: '#011932' },
            900: { class: 'bg-regal-navy-900', hex: '#000c19' },
        },
    },
    {
        name: 'Prussian Blue',
        slug: 'prussian-blue',
        shades: {
            100: { class: 'bg-prussian-blue-100', hex: '#a3c3ff' },
            200: { class: 'bg-prussian-blue-200', hex: '#4788ff' },
            300: { class: 'bg-prussian-blue-300', hex: '#0052eb' },
            400: { class: 'bg-prussian-blue-400', hex: '#00328f' },
            500: { class: 'bg-prussian-blue-500', hex: '#001233' },
            600: { class: 'bg-prussian-blue-600', hex: '#000e29' },
            700: { class: 'bg-prussian-blue-700', hex: '#000b1f' },
            800: { class: 'bg-prussian-blue-800', hex: '#000714' },
            900: { class: 'bg-prussian-blue-900', hex: '#00040a' },
        },
    },
    {
        name: 'Danger',
        slug: 'danger',
        shades: {
            100: { class: 'bg-danger-100', hex: '#ffcdd2' },
            200: { class: 'bg-danger-200', hex: '#e57373' },
            300: { class: 'bg-danger-300', hex: '#d32f2f' },
            400: { class: 'bg-danger-400', hex: '#ba181b' },
            500: { class: 'bg-danger-500', hex: '#a4161a' },
            600: { class: 'bg-danger-600', hex: '#8d0a0c' },
            700: { class: 'bg-danger-700', hex: '#660708' },
            800: { class: 'bg-danger-800', hex: '#330608' },
            900: { class: 'bg-danger-900', hex: '#1a0304' },
        },
    },
    {
        name: 'Success',
        slug: 'success',
        shades: {
            100: { class: 'bg-success-100', hex: '#b7efc5' },
            200: { class: 'bg-success-200', hex: '#6ede8a' },
            300: { class: 'bg-success-300', hex: '#4ad66d' },
            400: { class: 'bg-success-400', hex: '#2dc653' },
            500: { class: 'bg-success-500', hex: '#25a244' },
            600: { class: 'bg-success-600', hex: '#208b3a' },
            700: { class: 'bg-success-700', hex: '#1a7431' },
            800: { class: 'bg-success-800', hex: '#155d27' },
            900: { class: 'bg-success-900', hex: '#10451d' },
        },
    },
    {
        name: 'Twilight Indigo',
        slug: 'twilight-indigo',
        shades: {
            100: { class: 'bg-twilight-indigo-100', hex: '#d0d7e5' },
            200: { class: 'bg-twilight-indigo-200', hex: '#a0aecb' },
            300: { class: 'bg-twilight-indigo-300', hex: '#7186b1' },
            400: { class: 'bg-twilight-indigo-400', hex: '#4d628b' },
            500: { class: 'bg-twilight-indigo-500', hex: '#33415c' },
            600: { class: 'bg-twilight-indigo-600', hex: '#29344a' },
            700: { class: 'bg-twilight-indigo-700', hex: '#1e2737' },
            800: { class: 'bg-twilight-indigo-800', hex: '#141a25' },
            900: { class: 'bg-twilight-indigo-900', hex: '#0a0d12' },
        },
    },
    {
        name: 'Blue Slate',
        slug: 'blue-slate',
        shades: {
            100: { class: 'bg-blue-slate-100', hex: '#dee0e6' },
            200: { class: 'bg-blue-slate-200', hex: '#bcc2ce' },
            300: { class: 'bg-blue-slate-300', hex: '#9ba3b5' },
            400: { class: 'bg-blue-slate-400', hex: '#79859c' },
            500: { class: 'bg-blue-slate-500', hex: '#5c677d' },
            600: { class: 'bg-blue-slate-600', hex: '#4b5365' },
            700: { class: 'bg-blue-slate-700', hex: '#383f4c' },
            800: { class: 'bg-blue-slate-800', hex: '#252a32' },
            900: { class: 'bg-blue-slate-900', hex: '#131519' },
        },
    },
    {
        name: 'Slate Grey',
        slug: 'slate-grey',
        shades: {
            100: { class: 'bg-slate-grey-100', hex: '#e5e7ea' },
            200: { class: 'bg-slate-grey-200', hex: '#cbced5' },
            300: { class: 'bg-slate-grey-300', hex: '#b1b6c0' },
            400: { class: 'bg-slate-grey-400', hex: '#979dab' },
            500: { class: 'bg-slate-grey-500', hex: '#7d8597' },
            600: { class: 'bg-slate-grey-600', hex: '#62697a' },
            700: { class: 'bg-slate-grey-700', hex: '#4a4f5c' },
            800: { class: 'bg-slate-grey-800', hex: '#31353d' },
            900: { class: 'bg-slate-grey-900', hex: '#191a1f' },
        },
    },
    {
        name: 'Lavender Grey',
        slug: 'lavender-grey',
        shades: {
            100: { class: 'bg-lavender-grey-100', hex: '#eaebee' },
            200: { class: 'bg-lavender-grey-200', hex: '#d5d7dd' },
            300: { class: 'bg-lavender-grey-300', hex: '#c0c4cd' },
            400: { class: 'bg-lavender-grey-400', hex: '#abb0bc' },
            500: { class: 'bg-lavender-grey-500', hex: '#979dac' },
            600: { class: 'bg-lavender-grey-600', hex: '#737a8e' },
            700: { class: 'bg-lavender-grey-700', hex: '#565c6b' },
            800: { class: 'bg-lavender-grey-800', hex: '#393d47' },
            900: { class: 'bg-lavender-grey-900', hex: '#1d1f24' },
        },
    },
];

const shadeOrder: (keyof ColorPalette['shades'])[] = [100, 200, 300, 400, 500, 600, 700, 800, 900];
const copiedState = ref<Record<string, boolean>>({});

function copyToClipboard(text: string, key: string): void {
    navigator.clipboard.writeText(text).then(() => {
        copiedState.value[key] = true;
        setTimeout(() => {
            copiedState.value[key] = false;
        }, 2000);
    });
}

function getTextColor(shade: keyof ColorPalette['shades']): string {
    // Text is displayed below the swatch on the dark page background
    // So all text should be light for visibility on dark background
    return 'text-twilight-indigo-100';
}
</script>

<template>
    <GuidelinesPageLayout>
                <PageHeader title="Color Palette" subtitle="Design system color palette with click-to-copy functionality" />

                <Section title="Color Palette">
                    <div class="space-y-12">
                        <p class="text-base text-twilight-indigo-100 mb-6">
                            Click any color swatch to copy its hex value, or shift+click to copy the Tailwind class name.
                        </p>

                        <!-- Main Color Palettes -->
                        <div v-for="palette in colorPalettes" :key="palette.slug" class="space-y-3">
                            <Heading as="h3" size="xl" weight="semibold" color="smart-blue">
                                {{ palette.name }}
                            </Heading>
                            <div class="flex gap-1 overflow-x-auto pb-2 overflow-y-visible">
                                <div v-for="shade in shadeOrder" :key="shade"
                                    class="shrink-0 flex flex-col items-center group cursor-pointer py-1"
                                    @click="copyToClipboard(palette.shades[shade].hex, `${palette.slug}-${shade}`)"
                                    @click.shift="copyToClipboard(palette.shades[shade].class, `${palette.slug}-${shade}-class`)">
                                    <div :class="[
                                        'w-16 h-16 rounded transition-all hover:scale-105 hover:ring-2 hover:ring-smart-blue-400',
                                        palette.shades[shade].class,
                                    ]"></div>
                                    <div class="mt-2 flex flex-col items-center gap-1">
                                        <span :class="[
                                            'text-xs font-medium',
                                            getTextColor(shade),
                                        ]">
                                            {{ shade }}
                                        </span>
                                        <div v-if="copiedState[`${palette.slug}-${shade}`] || copiedState[`${palette.slug}-${shade}-class`]"
                                            class="flex items-center gap-1 text-xs text-success-400">
                                            <Check :size="12" />
                                            <span>Copied!</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Section>
    </GuidelinesPageLayout>
</template>

