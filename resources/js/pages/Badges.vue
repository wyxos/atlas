<script setup lang="ts">
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';
import Heading from '../components/ui/Heading.vue';

</script>

<template>
        <PageHeader title="Badges" subtitle="Compact status indicators and labels" />

        <div class="mt-8 space-y-12">
          <Section title="Variants">
            <div class="flex flex-wrap items-center gap-3">
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-smart-blue-700/30 text-smart-blue-100 border border-smart-blue-500">Primary</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-sapphire-700/30 text-sapphire-100 border border-sapphire-500">Secondary</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-danger-700/30 text-danger-100 border border-danger-500">Destructive</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-prussian-blue-700/30 text-twilight-indigo-100 border border-twilight-indigo-500">Neutral</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-success-700/30 text-success-100 border border-success-500">Success</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-warning-700/30 text-warning-100 border border-warning-500">Warning</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-info-700/30 text-info-100 border border-info-500">Info</span>
            </div>
          </Section>

          <Section title="Sizes">
            <div class="space-y-4">
              <div>
                <Heading as="h4" size="lg" weight="semibold" color="smart-blue" class="mb-2">Small</Heading>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-medium bg-smart-blue-700/30 text-smart-blue-100 border border-smart-blue-500">Primary</span>
                  <span class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-medium bg-prussian-blue-700 text-twilight-indigo-100 border border-twilight-indigo-500">Neutral</span>
                </div>
              </div>
              <div>
                <Heading as="h4" size="lg" weight="semibold" color="smart-blue" class="mb-2">Default</Heading>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-smart-blue-700/30 text-smart-blue-100 border border-smart-blue-500">Primary</span>
                  <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-prussian-blue-700/30 text-twilight-indigo-100 border border-twilight-indigo-500">Neutral</span>
                </div>
              </div>
              <div>
                <Heading as="h4" size="lg" weight="semibold" color="smart-blue" class="mb-2">Large</Heading>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="inline-flex items-center rounded px-3 py-1 text-sm font-medium bg-smart-blue-700/30 text-smart-blue-100 border border-smart-blue-500">Primary</span>
                  <span class="inline-flex items-center rounded px-3 py-1 text-sm font-medium bg-prussian-blue-700 text-twilight-indigo-100 border border-twilight-indigo-500">Neutral</span>
                </div>
              </div>
            </div>
          </Section>

          <Section title="With Icons">
            <div class="flex flex-wrap items-center gap-3">
              <span class="inline-flex items-center gap-1.5 rounded px-2.5 py-0.5 text-xs font-medium bg-success-700/30 text-success-100 border border-success-500">
                <span class="w-2 h-2 rounded-full bg-success-400"></span> Online
              </span>
              <span class="inline-flex items-center gap-1.5 rounded px-2.5 py-0.5 text-xs font-medium bg-warning-700/30 text-warning-100 border border-warning-500">
                <span class="w-2 h-2 rounded-full bg-warning-400"></span> Pending
              </span>
              <span class="inline-flex items-center gap-1.5 rounded px-2.5 py-0.5 text-xs font-medium bg-danger-700/30 text-danger-100 border border-danger-500">
                <span class="w-2 h-2 rounded-full bg-danger-400"></span> Error
              </span>
            </div>
          </Section>
        </div>
</template>


