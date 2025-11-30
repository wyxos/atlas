<script setup lang="ts">
import GuidelinesPageLayout from '../components/GuidelinesPageLayout.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';

</script>

<template>
  <GuidelinesPageLayout>
        <PageHeader title="Pills" subtitle="Rounded tags for categorization and filters" />

        <div class="mt-8 space-y-12">
          <Section title="Two‑Tone (Label + Value)">
            <div class="flex flex-wrap items-center gap-4">
              <!-- Default size -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                <span class="px-3 py-1 text-xs font-medium bg-smart-blue-600 text-white hover:bg-smart-blue-500 transition-colors">Smart Blue</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">42</span>
              </span>

              <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                <span class="px-3 py-1 text-xs font-medium bg-sapphire-600 text-white hover:bg-sapphire-500 transition-colors">Sapphire</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">9</span>
              </span>

              <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                <span class="px-3 py-1 text-xs font-medium bg-danger-600 text-white hover:bg-danger-500 transition-colors">Errors</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">3</span>
              </span>
            </div>
          </Section>

          <Section title="Sizes">
            <div class="space-y-4">
              <!-- Small -->
              <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                  <span class="px-2 py-0.5 text-[10px] font-medium bg-smart-blue-600 text-white hover:bg-smart-blue-500 transition-colors">Smart Blue</span>
                  <span class="px-2 py-0.5 text-[10px] font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">12</span>
                </span>
              </div>

              <!-- Default -->
              <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                  <span class="px-3 py-1 text-xs font-medium bg-sapphire-600 text-white hover:bg-sapphire-500 transition-colors">Sapphire</span>
                  <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">7</span>
                </span>
              </div>

              <!-- Large -->
              <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                  <span class="px-4 py-1.5 text-sm font-medium bg-smart-blue-600 text-white hover:bg-smart-blue-500 transition-colors">Uploads</span>
                  <span class="px-4 py-1.5 text-sm font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">128</span>
                </span>
              </div>
            </div>
          </Section>

          <Section title="Variants (Two‑Tone)">
            <div class="flex flex-wrap items-center gap-3">
              <!-- Primary -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-smart-blue-500">
                <span class="px-3 py-1 text-xs font-medium bg-smart-blue-600 text-white hover:bg-smart-blue-500 transition-colors">Primary</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-smart-blue-500 hover:bg-prussian-blue-600 transition-colors">24</span>
              </span>
              <!-- Secondary -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-sapphire-500">
                <span class="px-3 py-1 text-xs font-medium bg-sapphire-600 text-white hover:bg-sapphire-500 transition-colors">Secondary</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-sapphire-500 hover:bg-prussian-blue-600 transition-colors">12</span>
              </span>
              <!-- Neutral -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                <span class="px-3 py-1 text-xs font-medium bg-prussian-blue-600 text-twilight-indigo-100 hover:bg-prussian-blue-500 transition-colors">Neutral</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">7</span>
              </span>
              <!-- Success -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-success-500">
                <span class="px-3 py-1 text-xs font-medium bg-success-600 text-white hover:bg-success-500 transition-colors">Success</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-success-100 border-l border-success-500 hover:bg-prussian-blue-600 transition-colors">5</span>
              </span>
              <!-- Warning -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-warning-500">
                <span class="px-3 py-1 text-xs font-medium bg-warning-600 text-black hover:bg-warning-500 transition-colors">Warning</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-warning-100 border-l border-warning-500 hover:bg-prussian-blue-600 transition-colors">2</span>
              </span>
              <!-- Info -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-info-500">
                <span class="px-3 py-1 text-xs font-medium bg-info-600 text-white hover:bg-info-500 transition-colors">Info</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-info-100 border-l border-info-500 hover:bg-prussian-blue-600 transition-colors">18</span>
              </span>
            </div>
          </Section>

          <Section title="With Dismiss">
            <div class="flex flex-wrap items-center gap-3">
              <!-- Dismiss - Smart Blue -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-smart-blue-500">
                <span class="px-3 py-1 text-xs font-medium bg-smart-blue-600 text-white hover:bg-smart-blue-500 transition-colors">Smart Blue</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-smart-blue-500 hover:bg-prussian-blue-600 transition-colors">12</span>
                <button
                  type="button"
                  aria-label="Remove"
                  class="px-2 text-xs font-bold border-l border-smart-blue-500 bg-transparent text-twilight-indigo-300 hover:bg-smart-blue-600/40 hover:text-twilight-indigo-100 transition-colors"
                >
                  ×
                </button>
              </span>

              <!-- Dismiss - Neutral -->
              <span class="inline-flex items-stretch rounded overflow-hidden border border-twilight-indigo-500">
                <span class="px-3 py-1 text-xs font-medium bg-prussian-blue-600 text-twilight-indigo-100 hover:bg-prussian-blue-500 transition-colors">Neutral</span>
                <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-twilight-indigo-500 hover:bg-prussian-blue-600 transition-colors">4</span>
                <button
                  type="button"
                  aria-label="Remove"
                  class="px-2 text-xs font-bold border-l border-twilight-indigo-500 bg-transparent text-twilight-indigo-300 hover:bg-prussian-blue-600/60 hover:text-twilight-indigo-100 transition-colors"
                >
                  ×
                </button>
              </span>
            </div>
          </Section>
        </div>
  </GuidelinesPageLayout>
</template>


