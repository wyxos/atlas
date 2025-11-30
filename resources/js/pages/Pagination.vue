<script setup lang="ts">
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';
</script>

<template>
        <PageHeader title="Pagination" subtitle="Theme-consistent pagination patterns" />

        <div class="mt-8 space-y-12">
          <Section title="Default">
            <nav class="flex items-center justify-between">
              <!-- Prev -->
              <button class="inline-flex items-center gap-2 rounded px-3 py-1.5 text-sm border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">
                ‹ Prev
              </button>
              <!-- Pages -->
              <ol class="flex items-center gap-1">
                <li><button class="px-3 py-1.5 text-sm rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">1</button></li>
                <li><button class="px-3 py-1.5 text-sm rounded bg-smart-blue-600 text-white border border-smart-blue-500">2</button></li>
                <li><button class="px-3 py-1.5 text-sm rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">3</button></li>
              </ol>
              <!-- Next -->
              <button class="inline-flex items-center gap-2 rounded px-3 py-1.5 text-sm border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">
                Next ›
              </button>
            </nav>
          </Section>

          <Section title="Compact">
            <nav class="flex items-center justify-center gap-1">
              <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">‹</button>
              <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">1</button>
              <button class="h-8 w-8 grid place-items-center rounded bg-smart-blue-600 text-white border border-smart-blue-500">2</button>
              <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">3</button>
              <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">›</button>
            </nav>
          </Section>

          <Section title="With Results Summary">
            <div class="flex items-center justify-between text-sm text-twilight-indigo-300">
              <span>Showing 1–25 of 240</span>
              <nav class="flex items-center gap-1">
                <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">‹</button>
                <button class="h-8 w-8 grid place-items-center rounded bg-smart-blue-600 text-white border border-smart-blue-500">1</button>
                <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">2</button>
                <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">3</button>
                <button class="h-8 w-8 grid place-items-center rounded border border-twilight-indigo-500 text-twilight-indigo-100 hover:bg-prussian-blue-600">›</button>
              </nav>
            </div>
          </Section>
        </div>
</template>


