<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import PageLayout from '../components/PageLayout.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import Heading from '../components/ui/Heading.vue';
import Section from '../components/ui/Section.vue';
import Button from '../components/ui/button/Button.vue';

const route = useRoute();

const navigationItems = [
    {
        name: 'guidelines',
        path: '/guidelines',
        label: 'Overview',
    },
    {
        name: 'guidelines-buttons',
        path: '/guidelines#buttons',
        label: 'Buttons',
    },
    {
        name: 'guidelines-colors',
        path: '/guidelines/colors',
        label: 'Color Palette',
    },
];

const isActive = computed(() => (itemName: string, path: string) => {
    if (route.name === itemName) {
        return true;
    }
    // Handle hash-based navigation for buttons section
    if (itemName === 'guidelines-buttons' && route.path === '/guidelines' && route.hash === '#buttons') {
        return true;
    }
    // For overview, check if we're on guidelines without a hash
    if (itemName === 'guidelines' && route.path === '/guidelines' && !route.hash) {
        return true;
    }
    return false;
});

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
    <PageLayout>
        <div class="flex gap-8 w-full">
            <!-- Aside Navigation -->
            <aside class="w-64 shrink-0">
                <nav class="sticky top-8 space-y-1">
                    <router-link v-for="item in navigationItems" :key="item.name" :to="item.path"
                        class="block px-4 py-2 rounded-lg transition-colors text-twilight-indigo-100 hover:bg-prussian-blue-600 hover:text-smart-blue-100"
                        :class="{
                            'bg-prussian-blue-600 text-smart-blue-100': isActive(item.name, item.path),
                        }">
                        <span class="font-medium">{{ item.label }}</span>
                    </router-link>
                </nav>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 min-w-0">
                <PageHeader title="UI/UX Guidelines" subtitle="Design system and component showcase for Atlas" />

                <div class="mt-8 space-y-12">
                    <Section title="Welcome to the Guidelines">
                        <p class="text-base text-twilight-indigo-100 mb-6">
                            This is the guidelines overview page. Use the navigation on the left to explore different
                            sections of the design system.
                        </p>
                    </Section>

                    <Section id="buttons" title="Buttons">
                        <div class="space-y-8">
                            <div>
                                <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                                    Variants
                                </Heading>
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
                            </div>

                            <div>
                                <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                                    Sizes
                                </Heading>
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
                            </div>

                            <div>
                                <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                                    States
                                </Heading>
                                <p class="text-base text-twilight-indigo-100 mb-6">
                                    Buttons support different states including disabled and loading states.
                                </p>
                                <div class="flex flex-wrap gap-4">
                                    <Button>Normal</Button>
                                    <Button disabled>Disabled</Button>
                                    <Button loading>Loading</Button>
                                </div>
                            </div>

                            <div>
                                <Heading as="h3" size="xl" weight="semibold" color="smart-blue" class="mb-4">
                                    Color Variants
                                </Heading>
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
                                            <Button variant="outline">Cancel</Button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Section>
                </div>
            </main>
        </div>
    </PageLayout>
</template>
