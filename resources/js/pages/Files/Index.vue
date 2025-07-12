<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow
} from '@/components/ui/table';
import { formatDate } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ref, watch } from 'vue';
import { debounce } from 'lodash';

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Files',
    href: '/files',
  },
];

interface File {
  id: number;
  path: string;
  name: string;
  type: string;
  mime_type: string;
  not_found: boolean;
  created_at: string;
}

const props = defineProps<{
  files: {
    data: File[];
    links: any[];
    meta: any;
  };
  search: string;
  notFoundFilter: boolean;
}>();

const fileToDelete = ref<File | null>(null);
const showDeleteDialog = ref(false);
const searchQuery = ref(props.search);

const confirmDelete = () => {
  if (fileToDelete.value) {
    router.delete(`/files/${fileToDelete.value.id}`, {
      onSuccess: () => {
        showDeleteDialog.value = false;
        fileToDelete.value = null;
      }
    });
  }
};

const openDeleteDialog = (file: File) => {
  fileToDelete.value = file;
  showDeleteDialog.value = true;
};

// Debounced search function
const debouncedSearch = debounce((query: string) => {
  const params = new URLSearchParams(window.location.search);
  if (query) {
    params.set('query', query);
  } else {
    params.delete('query');
  }

  const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
  router.get(newUrl, {}, { preserveState: true });
}, 300);

// Watch for search input changes
watch(searchQuery, (newQuery) => {
  debouncedSearch(newQuery);
});

const getFileTypeColor = (mimeType: string): string => {
  if (mimeType.startsWith('audio/')) return 'text-blue-600';
  if (mimeType.startsWith('video/')) return 'text-green-600';
  if (mimeType.startsWith('image/')) return 'text-purple-600';
  return 'text-gray-600';
};
</script>

<template>
  <Head title="Files" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
      <div class="relative flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border p-4">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-xl font-semibold">
            File List
            <span v-if="notFoundFilter" class="text-sm text-red-600 ml-2">(Not Found Files)</span>
          </h2>
          <div class="w-64">
            <Input
              v-model="searchQuery"
              placeholder="Search files..."
              class="w-full"
            />
          </div>
        </div>

        <Table>
          <TableCaption>A list of all files in the system.</TableCaption>
          <TableHeader>
            <TableRow>
              <TableHead>ID</TableHead>
              <TableHead>Path</TableHead>
              <TableHead>Name</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Created At</TableHead>
              <TableHead>Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="file in files.data" :key="file.id">
              <TableCell class="font-medium">{{ file.id }}</TableCell>
              <TableCell class="max-w-xs truncate" :title="file.path">{{ file.path }}</TableCell>
              <TableCell>{{ file.name }}</TableCell>
              <TableCell :class="getFileTypeColor(file.mime_type)">{{ file.type }}</TableCell>
              <TableCell>
                <span v-if="file.not_found" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                  Not Found
                </span>
                <span v-else class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  Found
                </span>
              </TableCell>
              <TableCell>{{ formatDate(file.created_at) }}</TableCell>
              <TableCell>
                <div class="flex space-x-2">
                  <button
                    @click="openDeleteDialog(file)"
                    class="text-red-600 hover:text-red-800"
                  >
                    Delete
                  </button>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>

        <!-- Pagination -->
        <div v-if="files.links && files.links.length > 3" class="mt-4 flex justify-center">
          <nav class="flex space-x-2">
            <template v-for="link in files.links" :key="link.label">
              <Link
                v-if="link.url"
                :href="link.url"
                class="px-3 py-2 text-sm rounded-md"
                :class="{
                  'bg-blue-500 text-white': link.active,
                  'bg-gray-200 text-gray-700 hover:bg-gray-300': !link.active
                }"
              >
                {{ link.label }}
              </Link>
              <span
                v-else
                class="px-3 py-2 text-sm text-gray-400"
              >
                {{ link.label }}
              </span>
            </template>
          </nav>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Dialog -->
    <Dialog :open="showDeleteDialog" @update:open="showDeleteDialog = $event">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Confirm Deletion</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete the file "{{ fileToDelete?.name }}"? This action cannot be undone.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" @click="showDeleteDialog = false">Cancel</Button>
          <Button variant="destructive" @click="confirmDelete">Delete</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>
