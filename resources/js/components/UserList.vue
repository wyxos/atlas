<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import {
  Table,
  TableBody,
  TableCaption,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
// Import formatDate if it exists
// import { formatDate } from '@/utils';

interface User {
  id: number;
  name: string;
  email: string;
  created_at: string;
}

const props = defineProps<{
  users?: User[];
}>();

const users = ref<User[]>(props.users || []);

onMounted(() => {
  if (!props.users) {
    router.get(route('users.index'), {}, {
      preserveState: true,
      onSuccess: (page) => {
        users.value = page.props.users.data;
      }
    });
  }
});

// Format date helper function if not already defined in utils
function formatDateFallback(date: string): string {
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

const formatUserDate = (date: string): string => {
  return formatDateFallback(date);
};
</script>

<template>
  <div class="w-full">
    <Table>
      <TableCaption>A list of all users in the system.</TableCaption>
      <TableHeader>
        <TableRow>
          <TableHead>Name</TableHead>
          <TableHead>Email</TableHead>
          <TableHead>Joined</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        <TableRow v-for="user in users" :key="user.id">
          <TableCell class="font-medium">{{ user.name }}</TableCell>
          <TableCell>{{ user.email }}</TableCell>
          <TableCell>{{ formatUserDate(user.created_at) }}</TableCell>
        </TableRow>
        <TableRow v-if="users.length === 0">
          <TableCell colspan="3" class="text-center py-4">No users found.</TableCell>
        </TableRow>
      </TableBody>
    </Table>
  </div>
</template>
