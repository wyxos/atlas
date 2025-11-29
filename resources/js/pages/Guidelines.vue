<script setup lang="ts">
import { ref } from 'vue';
import { ArrowLeft, Trash2, CheckCircle2, Copy, Check } from 'lucide-vue-next';
import PageLayout from '../components/PageLayout.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';
import { Button } from '@/components/ui/button';
import Badge from '../components/ui/Badge.vue';
import FormInput from '../components/ui/FormInput.vue';
import Link from '../components/ui/Link.vue';
import Heading from '../components/ui/Heading.vue';
import { useRouter } from 'vue-router';

const router = useRouter();

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

const typographyExamples = [
    {
        element: 'h1',
        classes: 'text-5xl font-bold text-smart-blue-100',
        text: 'Heading 1',
    },
    {
        element: 'h2',
        classes: 'text-4xl font-bold text-smart-blue-100',
        text: 'Heading 2',
    },
    {
        element: 'h3',
        classes: 'text-3xl font-semibold text-regal-navy-100',
        text: 'Heading 3',
    },
    {
        element: 'h4',
        classes: 'text-2xl font-semibold text-regal-navy-100',
        text: 'Heading 4',
    },
    {
        element: 'h5',
        classes: 'text-xl font-medium text-sapphire-100',
        text: 'Heading 5',
    },
    {
        element: 'h6',
        classes: 'text-lg font-medium text-sapphire-100',
        text: 'Heading 6',
    },
    {
        element: 'p',
        classes: 'text-base text-twilight-indigo-100',
        text: 'Body text - Regular paragraph text that is easy to read and provides good contrast on dark backgrounds.',
    },
    {
        element: 'p',
        classes: 'text-sm text-slate-grey-300',
        text: 'Small text - Used for captions, hints, and secondary information.',
    },
];

const tableData = [
    {
        name: 'John Doe',
        email: 'john@example.com',
        role: 'Admin',
        status: 'active',
    },
    {
        name: 'Jane Smith',
        email: 'jane@example.com',
        role: 'User',
        status: 'active',
    },
    {
        name: 'Bob Johnson',
        email: 'bob@example.com',
        role: 'User',
        status: 'inactive',
    },
];

function goHome() {
    router.push('/');
}
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <PageHeader title="UI/UX Guidelines" subtitle="Design system and component showcase for Atlas" />

            <!-- Color Palette -->
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

                    <!-- Color Usage Examples -->
                    <div class="space-y-6 pt-8 border-t border-twilight-indigo-500">
                        <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-6">
                            Color Usage Examples
                        </Heading>

                        <div class="space-y-6">
                            <!-- Background Colors -->
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-4">
                                    Background Colors
                                </Heading>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="p-4 rounded-lg bg-smart-blue-500">
                                        <code class="text-xs text-smart-blue-100">bg-smart-blue-500</code>
                                    </div>
                                    <div class="p-4 rounded-lg bg-sapphire-500">
                                        <code class="text-xs text-sapphire-100">bg-sapphire-500</code>
                                    </div>
                                    <div class="p-4 rounded-lg bg-regal-navy-500">
                                        <code class="text-xs text-regal-navy-100">bg-regal-navy-500</code>
                                    </div>
                                </div>
                            </div>

                            <!-- Text Colors -->
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-4">
                                    Text Colors
                                </Heading>
                                <div class="space-y-2">
                                    <p class="text-smart-blue-300">text-smart-blue-300 - Primary text color</p>
                                    <p class="text-sapphire-300">text-sapphire-300 - Secondary text color</p>
                                    <p class="text-twilight-indigo-100">text-twilight-indigo-100 - Body text</p>
                                    <p class="text-success-400">text-success-400 - Success message</p>
                                    <p class="text-danger-400">text-danger-400 - Error message</p>
                                </div>
                            </div>

                            <!-- Border Colors -->
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-4">
                                    Border Colors
                                </Heading>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div class="p-4 rounded-lg border-2 border-smart-blue-400">
                                        <code class="text-xs text-twilight-indigo-100">border-smart-blue-400</code>
                                    </div>
                                    <div class="p-4 rounded-lg border-2 border-twilight-indigo-500">
                                        <code class="text-xs text-twilight-indigo-100">border-twilight-indigo-500</code>
                                    </div>
                                    <div class="p-4 rounded-lg border-2 border-success-400">
                                        <code class="text-xs text-twilight-indigo-100">border-success-400</code>
                                    </div>
                                </div>
                            </div>

                            <!-- Interactive States -->
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-4">
                                    Interactive States
                                </Heading>
                                <div class="flex flex-wrap gap-4">
                                    <button
                                        class="px-4 py-2 rounded-lg bg-smart-blue-500 text-white hover:bg-smart-blue-400 transition-colors">
                                        Hover: bg-smart-blue-400
                                    </button>
                                    <button
                                        class="px-4 py-2 rounded-lg border-2 border-sapphire-400 text-sapphire-300 hover:bg-sapphire-700 hover:border-sapphire-300 transition-colors">
                                        Hover: bg-sapphire-700
                                    </button>
                                    <button
                                        class="px-4 py-2 rounded-lg bg-danger-500 text-white hover:bg-danger-400 transition-colors">
                                        Hover: bg-danger-400
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Typography -->
            <Section title="Typography">
                <div class="space-y-6">
                    <div v-for="(example, index) in typographyExamples" :key="index">
                        <component :is="example.element" :class="example.classes + ' mb-2'">
                            {{ example.text }}
                        </component>
                        <code class="text-sm text-twilight-indigo-100">
                            {{ example.classes }}
                        </code>
                    </div>
                </div>
            </Section>

            <!-- Buttons -->
            <Section title="Buttons">
                <div class="space-y-8">
                    <!-- Button States Grid -->
                    <div>
                        <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                            Button States
                        </Heading>
                        <p class="text-sm text-twilight-indigo-300 mb-6">
                            All button variants support the following states: Default, Hover, Active, Focus, and
                            Disabled.
                        </p>
                        <div class="space-y-8">
                            <!-- Primary Buttons States -->
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-4">
                                    Primary (Filled) - Smart Blue
                                </Heading>
                                <div class="overflow-x-auto">
                                    <table class="w-full border-collapse">
                                        <thead>
                                            <tr class="bg-smart-blue-700 border-b-2 border-smart-blue-500">
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    State</th>
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    Example</th>
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    Classes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Default</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="default">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="default"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">Hover
                                                </td>
                                                <td class="px-4 py-3">
                                                    <Button variant="default">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="default"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Active</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="default">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="default"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">Focus
                                                </td>
                                                <td class="px-4 py-3">
                                                    <Button variant="default">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="default"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Disabled</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="default" disabled>
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code
                                                        class="text-xs text-smart-blue-300">variant="default" disabled</code>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Outline Buttons States -->
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-4">
                                    Outline - Smart Blue
                                </Heading>
                                <div class="overflow-x-auto">
                                    <table class="w-full border-collapse">
                                        <thead>
                                            <tr class="bg-smart-blue-700 border-b-2 border-smart-blue-500">
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    State</th>
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    Example</th>
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    Classes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Default</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="outline">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="outline"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">Hover
                                                </td>
                                                <td class="px-4 py-3">
                                                    <Button variant="outline">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="outline"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Active</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="outline">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="outline"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">Focus
                                                </td>
                                                <td class="px-4 py-3">
                                                    <Button variant="outline">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="outline"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Disabled</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="outline" disabled>
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code
                                                        class="text-xs text-smart-blue-300">variant="outline" disabled</code>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Ghost Buttons States -->
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-4">
                                    Ghost - Smart Blue
                                </Heading>
                                <div class="overflow-x-auto">
                                    <table class="w-full border-collapse">
                                        <thead>
                                            <tr class="bg-smart-blue-700 border-b-2 border-smart-blue-500">
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    State</th>
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    Example</th>
                                                <th
                                                    class="px-4 py-3 text-left text-sm font-semibold text-smart-blue-100">
                                                    Classes</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Default</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="ghost">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="ghost"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">Hover
                                                </td>
                                                <td class="px-4 py-3">
                                                    <Button variant="ghost">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="ghost"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Active</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="ghost">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="ghost"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">Focus
                                                </td>
                                                <td class="px-4 py-3">
                                                    <Button variant="ghost">
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code class="text-xs text-smart-blue-300">variant="ghost"</code>
                                                </td>
                                            </tr>
                                            <tr class="border-b border-twilight-indigo-500">
                                                <td class="px-4 py-3 text-sm text-twilight-indigo-100 font-medium">
                                                    Disabled</td>
                                                <td class="px-4 py-3">
                                                    <Button variant="ghost" disabled>
                                                        Button
                                                    </Button>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <code
                                                        class="text-xs text-smart-blue-300">variant="ghost" disabled</code>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Reference -->
                    <div>
                        <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                            Quick Reference
                        </Heading>
                        <div class="flex flex-wrap gap-4">
                            <Button variant="default">
                                Smart Blue Primary
                            </Button>
                            <Button variant="secondary">
                                Sapphire Primary
                            </Button>
                            <Button variant="default" disabled>
                                Disabled
                            </Button>
                        </div>
                        <div class="flex flex-wrap gap-4 mt-4">
                            <Button variant="outline">
                                Outline Smart Blue
                            </Button>
                            <Button variant="outline"
                                class="border-sapphire-600 text-sapphire-600 hover:bg-sapphire-300">
                                Outline Sapphire
                            </Button>
                        </div>
                        <div class="flex flex-wrap gap-4 mt-4">
                            <Button variant="ghost">
                                Ghost Smart Blue
                            </Button>
                            <Button variant="ghost"
                                class="border-sapphire-500/30 text-sapphire-300 hover:bg-sapphire-700/20 hover:border-sapphire-500/50">
                                Ghost Sapphire
                            </Button>
                        </div>
                    </div>
                    <div>
                        <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                            Button Sizes
                        </Heading>
                        <div class="space-y-4">
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-3">
                                    Primary (Filled)
                                </Heading>
                                <div class="flex flex-wrap items-center gap-4">
                                    <Button size="sm" variant="default">
                                        Small
                                    </Button>
                                    <Button size="default" variant="default">
                                        Medium
                                    </Button>
                                    <Button size="lg" variant="default">
                                        Large
                                    </Button>
                                </div>
                            </div>
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-3">
                                    Outline
                                </Heading>
                                <div class="flex flex-wrap items-center gap-4">
                                    <Button size="sm" variant="outline">
                                        Small
                                    </Button>
                                    <Button size="default" variant="outline">
                                        Medium
                                    </Button>
                                    <Button size="lg" variant="outline">
                                        Large
                                    </Button>
                                </div>
                            </div>
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-3">
                                    Ghost
                                </Heading>
                                <div class="flex flex-wrap items-center gap-4">
                                    <Button size="sm" variant="ghost">
                                        Small
                                    </Button>
                                    <Button size="default" variant="ghost">
                                        Medium
                                    </Button>
                                    <Button size="lg" variant="ghost">
                                        Large
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                            Danger Buttons
                        </Heading>
                        <div class="flex flex-wrap gap-4">
                            <Button variant="destructive">
                                Danger Primary
                            </Button>
                            <Button variant="outline"
                                class="border-danger-400 text-danger-400 hover:bg-danger-700 hover:border-danger-400 hover:text-danger-100">
                                Danger Outline
                            </Button>
                            <Button variant="ghost"
                                class="border-danger-500/30 text-danger-300 hover:bg-danger-700/20 hover:border-danger-500/50">
                                Danger Ghost
                            </Button>
                        </div>
                    </div>
                    <div>
                        <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                            Icon Buttons
                        </Heading>
                        <div class="space-y-6">
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-3">
                                    Primary (Filled)
                                </Heading>
                                <div class="flex flex-wrap gap-4">
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer bg-smart-blue-500 border-2 border-smart-blue-400 text-white hover:bg-smart-blue-400">
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer bg-danger-400 border-2 border-danger-300 text-white hover:bg-danger-700">
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer bg-success-500 border-2 border-success-400 text-white hover:bg-success-400">
                                        <CheckCircle2 :size="20" />
                                    </button>
                                </div>
                                <code class="block mt-2 text-xs text-twilight-indigo-100">
                                    h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg bg-smart-blue-500 border-2 border-smart-blue-400 text-white hover:bg-smart-blue-400
                                </code>
                            </div>
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-3">
                                    Outline
                                </Heading>
                                <div class="flex flex-wrap gap-4">
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer border-2 border-smart-blue-400 text-smart-blue-400 bg-transparent hover:bg-smart-blue-500 hover:border-smart-blue-400 hover:text-white">
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer border-2 border-danger-300 text-danger-300 bg-transparent hover:bg-danger-500 hover:border-danger-400 hover:text-white">
                                        <Trash2 :size="20" />
                                    </button>
                                </div>
                                <code class="block mt-2 text-xs text-twilight-indigo-100">
                                    h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg border-2 border-smart-blue-400 text-smart-blue-400 bg-transparent hover:bg-smart-blue-500 hover:border-smart-blue-400 hover:text-white
                                </code>
                            </div>
                            <div>
                                <Heading as="h4" size="lg" weight="medium" color="regal-navy" class="mb-3">
                                    Ghost
                                </Heading>
                                <div class="flex flex-wrap gap-4">
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer border border-smart-blue-500/30 text-smart-blue-300 bg-transparent hover:bg-smart-blue-700/20 hover:border-smart-blue-500/50">
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer border border-danger-500/30 text-danger-300 bg-transparent hover:bg-danger-700/20 hover:border-danger-500/50">
                                        <Trash2 :size="20" />
                                    </button>
                                </div>
                                <code class="block mt-2 text-xs text-twilight-indigo-100">
                                    h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg border border-smart-blue-500/30 text-smart-blue-300 bg-transparent hover:bg-smart-blue-700/20 hover:border-smart-blue-500/50
                                </code>
                            </div>
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Badges & Tags -->
            <Section title="Badges & Tags">
                <div class="space-y-4">
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Status Badges
                        </h3>
                        <div class="flex flex-wrap gap-4 items-center">
                            <Badge variant="verified" icon-only />
                            <Badge variant="active">Active</Badge>
                            <Badge variant="inactive">Inactive</Badge>
                            <Badge variant="error">Error</Badge>
                            <Badge variant="pending">Pending</Badge>
                        </div>
                        <code class="block mt-2 text-xs text-twilight-indigo-100">
                            Verified: inline-flex items-center justify-center p-1.5 rounded-sm bg-success-300 border-success-500 (icon only). Other badges: px-3 py-1 rounded-sm text-xs font-medium bg-* border-*
                        </code>
                    </div>
                </div>
            </Section>

            <!-- Form Inputs -->
            <Section title="Form Inputs">
                <div class="space-y-6 max-w-2xl">
                    <FormInput type="text" placeholder="Enter text here">
                        <template #label>Text Input</template>
                    </FormInput>
                    <FormInput type="email" placeholder="you@example.com">
                        <template #label>Email Input</template>
                    </FormInput>
                    <FormInput type="password" placeholder="••••••••">
                        <template #label>Password Input</template>
                    </FormInput>
                    <div>
                        <label class="block text-sm font-medium mb-2 text-smart-blue-100">
                            Textarea
                        </label>
                        <textarea rows="4" placeholder="Enter your message here..."
                            class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors resize-none bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-100 focus:border-smart-blue-400 focus:ring-smart-blue-600/20"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2 text-smart-blue-100">
                            Select Dropdown
                        </label>
                        <select
                            class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-100 focus:border-smart-blue-400 focus:ring-smart-blue-600/20">
                            <option class="bg-prussian-blue-500 text-twilight-indigo-100">
                                Option 1
                            </option>
                            <option class="bg-prussian-blue-500 text-twilight-indigo-100">
                                Option 2
                            </option>
                            <option class="bg-prussian-blue-500 text-twilight-indigo-100">
                                Option 3
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" class="w-4 h-4 rounded focus:ring-2 accent-smart-blue-500" />
                            <span class="text-sm text-twilight-indigo-100">
                                Checkbox option
                            </span>
                        </label>
                    </div>
                    <div>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="radio-example"
                                class="w-4 h-4 focus:ring-2 accent-smart-blue-500" />
                            <span class="text-sm text-twilight-indigo-100">
                                Radio option 1
                            </span>
                        </label>
                        <label class="flex items-center gap-2 mt-2">
                            <input type="radio" name="radio-example"
                                class="w-4 h-4 focus:ring-2 accent-smart-blue-500" />
                            <span class="text-sm text-twilight-indigo-100">
                                Radio option 2
                            </span>
                        </label>
                    </div>
                </div>
            </Section>

            <!-- Tables -->
            <Section title="Tables">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-smart-blue-700 border-b-2 border-smart-blue-500">
                                <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100">
                                    Name
                                </th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100">
                                    Email
                                </th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100">
                                    Role
                                </th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100">
                                    Status
                                </th>
                                <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in tableData" :key="index"
                                class="transition-colors border-b border-twilight-indigo-500 hover:bg-smart-blue-700">
                                <td class="px-6 py-4 text-sm text-twilight-indigo-100">
                                    {{ row.name }}
                                </td>
                                <td class="px-6 py-4 text-sm text-twilight-indigo-100">
                                    {{ row.email }}
                                </td>
                                <td class="px-6 py-4 text-sm text-twilight-indigo-100">
                                    {{ row.role }}
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <Badge :variant="row.status === 'active' ? 'active' : 'inactive'">
                                        {{ row.status === 'active' ? 'Active' : 'Inactive' }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <button
                                        class="font-medium transition-colors cursor-pointer text-smart-blue-300 hover:text-smart-blue-400">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Section>

            <!-- Links -->
            <Section title="Links">
                <div class="space-y-4">
                    <div>
                        <Link variant="default" href="#">Default Link</Link>
                    </div>
                    <div>
                        <Link variant="no-underline" href="#">
                        Link without underline
                        </Link>
                    </div>
                    <div>
                        <Link variant="sapphire" href="#">Sapphire Link</Link>
                    </div>
                    <div>
                        <Link variant="muted" href="#">Muted Link</Link>
                    </div>
                </div>
            </Section>

            <!-- Pagination -->
            <Section title="Pagination">
                <div class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Pagination Controls
                        </h3>
                        <div class="bg-prussian-blue-500 rounded-lg p-6">
                            <div class="flex justify-end">
                                <nav class="flex items-center gap-2" aria-label="Pagination">
                                    <button disabled
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-500 cursor-not-allowed opacity-40 transition-all">
                                        <ArrowLeft :size="20" />
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-smart-blue-400 rounded-lg bg-smart-blue-500 text-white font-semibold transition-all"
                                        aria-current="page">
                                        1
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 transition-all cursor-pointer">
                                        2
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 transition-all cursor-pointer">
                                        3
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 transition-all cursor-pointer">
                                        <ArrowLeft :size="20" class="rotate-180" />
                                    </button>
                                </nav>
                            </div>
                        </div>
                        <div class="mt-4 p-4 bg-prussian-blue-500 rounded-lg">
                            <p class="text-sm text-twilight-indigo-100 mb-2">
                                <strong>Tailwind Classes:</strong>
                            </p>
                            <code class="text-xs text-smart-blue-300 block whitespace-pre-wrap">
                    flex items-center justify-center min-w-[2.5rem] h-10 px-3 border rounded-lg transition-all
                    border-smart-blue-400 bg-smart-blue-500 text-white font-semibold (current page)
                    border-twilight-indigo-500 bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700
                    hover:border-smart-blue-400 hover:text-smart-blue-100 (page buttons)
                    opacity-40 cursor-not-allowed (disabled)</code>
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Scrollbars -->
            <Section title="Scrollbars">
                <div class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Custom Scrollbar Styling
                        </h3>
                        <p class="text-base text-twilight-indigo-100 mb-4">
                            Scrollbars are styled to match the dark theme. The scrollbar track uses
                            Twilight Indigo 300, the thumb uses Twilight Indigo 600, and on hover it
                            changes to Smart Blue 600.
                        </p>
                        <div class="bg-prussian-blue-500 rounded-lg p-6">
                            <div class="h-64 overflow-y-auto pr-4">
                                <div class="space-y-4">
                                    <p class="text-base text-twilight-indigo-100">
                                        This is a scrollable container demonstrating the custom
                                        scrollbar styling. Scroll down to see the scrollbar in action.
                                    </p>
                                    <div v-for="i in 20" :key="i" class="p-4 bg-prussian-blue-400 rounded-lg">
                                        <Heading as="h4" size="lg" weight="semibold" color="smart-blue" class="mb-2">
                                            Item {{ i }}
                                        </Heading>
                                        <p class="text-sm text-twilight-indigo-100">
                                            This is content item {{ i }}. The scrollbar on the right
                                            demonstrates the custom styling with Twilight Indigo
                                            colors that match the dark theme.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 p-4 bg-prussian-blue-500 rounded-lg">
                            <p class="text-sm text-twilight-indigo-100 mb-2">
                                <strong>CSS Properties:</strong>
                            </p>
                            <code class="text-xs text-smart-blue-300 block whitespace-pre-wrap">
                    /* Webkit browsers (Chrome, Safari, Edge) */
                    ::-webkit-scrollbar {
                    width: 12px;
                    height: 12px;
                    }

                    ::-webkit-scrollbar-track {
                    background: #1e2737; /* twilight-indigo-300 */
                    border-radius: 6px;
                    }

                    ::-webkit-scrollbar-thumb {
                    background: #4d628b; /* twilight-indigo-600 */
                    border-radius: 6px;
                    border: 2px solid #1e2737; /* twilight-indigo-300 */
                    }

                    ::-webkit-scrollbar-thumb:hover {
                    background: #0f85fa; /* smart-blue-600 */
                    }

                    /* Firefox */
                    * {
                    scrollbar-width: thin;
                    scrollbar-color: #4d628b #1e2737; /* thumb track */
                    }</code>
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Navigation -->
            <div class="mt-8 pt-8 border-t-2 border-twilight-indigo-500">
                <Link variant="no-underline" href="#" @click.prevent="goHome" class="inline-flex items-center gap-2">
                <ArrowLeft :size="20" />
                Back to Home
                </Link>
            </div>
        </div>
    </PageLayout>
</template>
