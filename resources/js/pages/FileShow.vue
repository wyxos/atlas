<script setup lang="ts">
  import { Head } from '@inertiajs/vue3'
  import AppLayout from "@/layouts/AppLayout.vue";

  defineProps<{
      file: {
          id: number
          name: string
          url: string
          type: string
          size: number
          created_at: string
      },
        metadata: {
            id: number
            file_id: number
            key: string
            value: string
        },
        rawMetadata: {}
  }>()
</script>

<template>
    <Head title="FileShow" />

    <AppLayout>
        <pre>{{ file }}</pre>

        <pre>{{ metadata }}</pre>
        <pre>{{ rawMetadata }}</pre>
    </AppLayout>
</template>
