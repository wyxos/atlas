<script setup lang="ts">
import PageHeader from '../components/ui/PageHeader.vue';
import Heading from '../components/ui/Heading.vue';
import Section from '../components/ui/Section.vue';
import Button from '../components/ui/button/Button.vue';

const buttonVariants = [
    { name: 'Default', variant: 'default' as const },
    { name: 'Secondary', variant: 'secondary' as const },
    { name: 'Destructive', variant: 'destructive' as const },
    { name: 'Outline', variant: 'outline' as const },
    { name: 'Ghost', variant: 'ghost' as const },
    { name: 'Link', variant: 'link' as const },
];

const buttonSizes = [
    { name: 'Small', size: 'sm' as const },
    { name: 'Default', size: 'default' as const },
    { name: 'Large', size: 'lg' as const },
];
</script>

<template>
    <PageHeader title="Buttons" subtitle="Interactive elements for user actions" />

    <div class="mt-8 space-y-12">
        <Section title="Variants">
            <p class="text-base text-twilight-indigo-100 mb-6">
                Buttons come in different variants to convey different actions and importance
                levels.
                All variants are styled to match our color palette with Smart Blue for primary
                actions,
                Sapphire for secondary actions, and appropriate colors for destructive actions.
            </p>
            <div class="flex flex-wrap gap-4">
                <Button v-for="btn in buttonVariants" :key="btn.variant" :variant="btn.variant">
                    {{ btn.name }}
                </Button>
            </div>
        </Section>

        <Section title="Sizes">
            <p class="text-base text-twilight-indigo-100 mb-6">
                Buttons are available in three sizes: small, default, and large. Choose the size
                that
                best fits your interface hierarchy.
            </p>
            <div class="flex flex-wrap items-center gap-4">
                <Button v-for="size in buttonSizes" :key="size.size" :size="size.size">
                    {{ size.name }}
                </Button>
            </div>
        </Section>

        <Section title="States">
            <p class="text-base text-twilight-indigo-100 mb-6">
                Buttons support different states including disabled and loading states.
            </p>
            <div class="flex flex-wrap gap-4">
                <Button>Normal</Button>
                <Button disabled>Disabled</Button>
                <Button loading>Loading</Button>
            </div>
        </Section>

        <Section title="Color Variants">
            <p class="text-base text-twilight-indigo-100 mb-6">
                Buttons use our color palette:
            </p>
            <div class="space-y-4">
                <div>
                    <p class="text-sm text-twilight-indigo-200 mb-2">Primary (Smart Blue)</p>
                    <div class="flex flex-wrap gap-4">
                        <Button variant="default">Primary Button</Button>
                        <Button variant="outline">Primary Outline</Button>
                        <Button variant="ghost">Primary Ghost</Button>
                    </div>
                </div>
                <div>
                    <p class="text-sm text-twilight-indigo-200 mb-2">Secondary (Sapphire)</p>
                    <div class="flex flex-wrap gap-4">
                        <Button variant="secondary">Secondary Button</Button>
                    </div>
                </div>
                <div>
                    <p class="text-sm text-twilight-indigo-200 mb-2">Destructive (Danger)</p>
                    <div class="flex flex-wrap gap-4">
                        <Button variant="destructive">Delete</Button>
                        <Button variant="outline" color="danger">Cancel</Button>
                    </div>
                </div>
            </div>
        </Section>
    </div>
</template>
