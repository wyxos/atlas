<script setup lang="ts">
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';

const sampleRows = [
  { id: 1, name: 'Smart Blue', value: '#0466c8', usage: 'Primary actions' },
  { id: 2, name: 'Sapphire', value: '#0353a4', usage: 'Secondary actions' },
  { id: 3, name: 'Regal Navy', value: '#023e7d', usage: 'Headings' },
];
</script>

<template>
        <PageHeader title="Tables" subtitle="How tables should look with our theme" />

        <div class="mt-8 space-y-12">
          <Section title="Basic Table">
            <div class="overflow-x-auto rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
              <table class="min-w-full divide-y divide-twilight-indigo-500">
                <thead class="bg-prussian-blue-800">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-twilight-indigo-300 uppercase tracking-wider">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-twilight-indigo-300 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-twilight-indigo-300 uppercase tracking-wider">Value</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-twilight-indigo-300 uppercase tracking-wider">Usage</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-twilight-indigo-500">
                  <tr v-for="row in sampleRows" :key="row.id" class="hover:bg-smart-blue-700/20 transition-colors">
                    <td class="px-4 py-3 text-twilight-indigo-100">{{ row.id }}</td>
                    <td class="px-4 py-3 text-twilight-indigo-100">{{ row.name }}</td>
                    <td class="px-4 py-3">
                      <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-prussian-blue-600 border border-twilight-indigo-500 text-twilight-indigo-100">{{ row.value }}</span>
                    </td>
                    <td class="px-4 py-3 text-twilight-indigo-100">{{ row.usage }}</td>
                  </tr>
                </tbody>
                <tfoot class="bg-prussian-blue-800">
                  <tr>
                    <td colspan="4" class="px-4 py-3 text-right text-xs text-twilight-indigo-300">
                      Showing {{ sampleRows.length }} results
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </Section>

          <Section title="Compact Table">
            <div class="overflow-x-auto rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
              <table class="min-w-full divide-y divide-twilight-indigo-500">
                <thead class="bg-prussian-blue-800">
                  <tr>
                    <th class="px-3 py-2 text-left text-[10px] font-medium text-twilight-indigo-300 uppercase tracking-wider">Token</th>
                    <th class="px-3 py-2 text-left text-[10px] font-medium text-twilight-indigo-300 uppercase tracking-wider">Hex</th>
                    <th class="px-3 py-2 text-left text-[10px] font-medium text-twilight-indigo-300 uppercase tracking-wider">Comment</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-twilight-indigo-500">
                  <tr class="hover:bg-smart-blue-700/20 transition-colors">
                    <td class="px-3 py-2 text-twilight-indigo-100">--color-smart-blue-500</td>
                    <td class="px-3 py-2 text-twilight-indigo-100">#0466c8</td>
                    <td class="px-3 py-2 text-twilight-indigo-100">Primary brand</td>
                  </tr>
                  <tr class="hover:bg-smart-blue-700/20 transition-colors">
                    <td class="px-3 py-2 text-twilight-indigo-100">--color-prussian-blue-700</td>
                    <td class="px-3 py-2 text-twilight-indigo-100">#000b1f</td>
                    <td class="px-3 py-2 text-twilight-indigo-100">Page bg</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </Section>
        </div>
</template>


