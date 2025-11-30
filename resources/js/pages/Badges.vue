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
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-smart-blue-600 text-white border border-smart-blue-500 hover:bg-smart-blue-500 transition-colors">Primary</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-sapphire-600 text-white border border-sapphire-500 hover:bg-sapphire-500 transition-colors">Secondary</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-danger-600 text-white border border-danger-500 hover:bg-danger-500 transition-colors">Destructive</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-prussian-blue-600 text-twilight-indigo-100 border border-twilight-indigo-500 hover:bg-prussian-blue-500 transition-colors">Neutral</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-success-600 text-white border border-success-500 hover:bg-success-500 transition-colors">Success</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-warning-600 text-black border border-warning-500 hover:bg-warning-500 transition-colors">Warning</span>
              <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-info-600 text-white border border-info-500 hover:bg-info-500 transition-colors">Info</span>
            </div>
          </Section>

          <Section title="Sizes">
            <div class="space-y-4">
              <div>
                <Heading as="h4" size="lg" weight="semibold" color="smart-blue" class="mb-2">Small</Heading>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-medium bg-smart-blue-600 text-white border border-smart-blue-500 hover:bg-smart-blue-500 transition-colors">Primary</span>
                  <span class="inline-flex items-center rounded px-2 py-0.5 text-[10px] font-medium bg-prussian-blue-600 text-twilight-indigo-100 border border-twilight-indigo-500 hover:bg-prussian-blue-500 transition-colors">Neutral</span>
                </div>
              </div>
              <div>
                <Heading as="h4" size="lg" weight="semibold" color="smart-blue" class="mb-2">Default</Heading>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-smart-blue-600 text-white border border-smart-blue-500 hover:bg-smart-blue-500 transition-colors">Primary</span>
                  <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-prussian-blue-600 text-twilight-indigo-100 border border-twilight-indigo-500 hover:bg-prussian-blue-500 transition-colors">Neutral</span>
                </div>
              </div>
              <div>
                <Heading as="h4" size="lg" weight="semibold" color="smart-blue" class="mb-2">Large</Heading>
                <div class="flex flex-wrap items-center gap-3">
                  <span class="inline-flex items-center rounded px-3 py-1 text-sm font-medium bg-smart-blue-600 text-white border border-smart-blue-500 hover:bg-smart-blue-500 transition-colors">Primary</span>
                  <span class="inline-flex items-center rounded px-3 py-1 text-sm font-medium bg-prussian-blue-600 text-twilight-indigo-100 border border-twilight-indigo-500 hover:bg-prussian-blue-500 transition-colors">Neutral</span>
                </div>
              </div>
            </div>
          </Section>

          <Section title="With Icons">
            <div class="flex flex-wrap items-center gap-3">
              <span class="inline-flex items-center gap-1.5 rounded px-2.5 py-0.5 text-xs font-medium bg-success-600 text-white border border-success-500 hover:bg-success-500 transition-colors">
                <span class="w-2 h-2 rounded-full bg-success-400"></span> Online
              </span>
              <span class="inline-flex items-center gap-1.5 rounded px-2.5 py-0.5 text-xs font-medium bg-warning-600 text-black border border-warning-500 hover:bg-warning-500 transition-colors">
                <span class="w-2 h-2 rounded-full bg-warning-400"></span> Pending
              </span>
              <span class="inline-flex items-center gap-1.5 rounded px-2.5 py-0.5 text-xs font-medium bg-danger-600 text-white border border-danger-500 hover:bg-danger-500 transition-colors">
                <span class="w-2 h-2 rounded-full bg-danger-400"></span> Error
              </span>
            </div>
          </Section>
        </div>
</template>


