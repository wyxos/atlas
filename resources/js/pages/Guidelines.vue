<script setup lang="ts">
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';
</script>

<template>
        <!-- Codex UI note: before Atlas UI work, use this Guidelines source as the project reference. Side panels that toggle like sheets, including the audio playlist panel, should match sheet motion: ease-in-out, 500ms enter, 300ms leave, and side slide/fade from the panel edge. When a panel changes the main work area's width, animate the panel frame width too so the main surface resizes smoothly instead of snapping. -->
        <PageHeader title="UI/UX Guidelines" subtitle="Design system and component showcase for Atlas" />

        <div class="mt-8 space-y-12">
            <Section title="Welcome to the Guidelines">
                <p class="text-base text-twilight-indigo-100 mb-6">
                    This is the guidelines overview page. Use the navigation on the left to explore different
                    sections of the design system.
                </p>
            </Section>

        </div>
</template>
