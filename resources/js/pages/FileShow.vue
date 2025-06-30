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
          covers: Array<{
              id: number
              path: string
              hash: string
          }>,
          artists: Array<{
              id: number
              name: string
          }>,
          albums: Array<{
              id: number
              name: string
          }>
      },
        metadata: {
            id: number
            file_id: number
            key: string
            value: string
        },
        rawMetadata: Record<string, any>
  }>()
</script>

<template>
    <Head title="FileShow" />

    <AppLayout>
        <!-- Display cover if available -->
        <div v-if="file.covers && file.covers.length > 0" class="mb-4">
            <h2 class="text-lg font-semibold mb-2">Cover Art</h2>
            <img
                v-for="cover in file.covers"
                :key="cover.id"
                :src="`/storage/${cover.path}`"
                alt="Cover Art"
                class="max-w-xs rounded shadow"
            />
        </div>

        <!-- Display artists if available -->
        <div v-if="file.artists && file.artists.length > 0" class="mb-4">
            <h2 class="text-lg font-semibold mb-2">Artists</h2>
            <ul>
                <li v-for="artist in file.artists" :key="artist.id">
                    {{ artist.name }}
                </li>
            </ul>
        </div>

        <!-- Display albums if available -->
        <div v-if="file.albums && file.albums.length > 0" class="mb-4">
            <h2 class="text-lg font-semibold mb-2">Albums</h2>
            <ul>
                <li v-for="album in file.albums" :key="album.id">
                    {{ album.name }}
                </li>
            </ul>
        </div>

        <pre>{{ file }}</pre>

        <pre>{{ metadata }}</pre>
        <pre>{{ rawMetadata }}</pre>
    </AppLayout>
</template>
