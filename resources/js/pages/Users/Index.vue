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
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Users',
    href: '/users',
  },
];

interface User {
  id: number;
  name: string;
  email: string;
  created_at: string;
  last_login_at: string | null;
  last_login_ip: string | null;
}

defineProps<{
  users: {
    data: User[];
    links: any[];
    meta: any;
  };
}>();

const userToDelete = ref<User | null>(null);
const showDeleteDialog = ref(false);

const confirmDelete = () => {
  if (userToDelete.value) {
    router.delete(`/users/${userToDelete.value.id}`, {
      onSuccess: () => {
        showDeleteDialog.value = false;
        userToDelete.value = null;
      }
    });
  }
};

const openDeleteDialog = (user: User) => {
  userToDelete.value = user;
  showDeleteDialog.value = true;
};
</script>

<template>
  <Head title="Users" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
      <div class="relative flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border p-4">
        <h2 class="text-xl font-semibold mb-4">User List</h2>
        <Table>
          <TableCaption>A list of all users in the system.</TableCaption>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Email</TableHead>
              <TableHead>Created At</TableHead>
              <TableHead>Last Login</TableHead>
              <TableHead>Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow v-for="user in users.data" :key="user.id">
              <TableCell class="font-medium">{{ user.name }}</TableCell>
              <TableCell>{{ user.email }}</TableCell>
              <TableCell>{{ formatDate(user.created_at) }}</TableCell>
              <TableCell>{{ user.last_login_at ? formatDate(user.last_login_at) : 'Never' }}</TableCell>
              <TableCell>
                <div class="flex space-x-2">
                  <Link :href="`/users/${user.id}/edit`" class="text-blue-600 hover:text-blue-800">
                    Edit
                  </Link>
                  <button
                    @click="openDeleteDialog(user)"
                    class="text-red-600 hover:text-red-800"
                  >
                    Delete
                  </button>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </Table>
      </div>
    </div>

    <!-- Delete Confirmation Dialog -->
    <Dialog :open="showDeleteDialog" @update:open="showDeleteDialog = $event">
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Confirm Deletion</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete the user "{{ userToDelete?.name }}"? This action cannot be undone.
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
