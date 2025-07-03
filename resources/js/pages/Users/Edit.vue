<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import {
  Form,
  FormControl,
  FormField,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { toast } from '@/components/ui/toast/use-toast';

interface User {
  id: number;
  name: string;
  email: string;
}

const props = defineProps<{
  user: User;
}>();

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Users',
    href: '/users',
  },
  {
    title: 'Edit User',
    href: `/users/${props.user.id}/edit`,
  },
];

const form = useForm({
  name: props.user.name,
  email: props.user.email,
});

const submit = () => {
  form.put(`/users/${props.user.id}`, {
    onSuccess: () => {
      toast({
        title: 'Success',
        description: 'User updated successfully',
      });
    },
  });
};
</script>

<template>
  <Head title="Edit User" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
      <div class="relative flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border p-4">
        <Card>
          <CardHeader>
            <CardTitle>Edit User</CardTitle>
            <CardDescription>Update user information</CardDescription>
          </CardHeader>
          <CardContent>
            <Form @submit.prevent="submit" :class="{ 'opacity-50': form.processing }">
              <div class="grid gap-4">
                <FormField
                  :id="'name'"
                  name="name"
                  :invalid="form.errors.name !== undefined"
                  :error="form.errors.name"
                >
                  <FormLabel>Name</FormLabel>
                  <FormControl>
                    <Input v-model="form.name" :disabled="form.processing" />
                  </FormControl>
                  <FormMessage v-if="form.errors.name">{{ form.errors.name }}</FormMessage>
                </FormField>

                <FormField
                  :id="'email'"
                  name="email"
                  :invalid="form.errors.email !== undefined"
                  :error="form.errors.email"
                >
                  <FormLabel>Email</FormLabel>
                  <FormControl>
                    <Input v-model="form.email" type="email" :disabled="form.processing" />
                  </FormControl>
                  <FormMessage v-if="form.errors.email">{{ form.errors.email }}</FormMessage>
                </FormField>
              </div>

              <div class="mt-6 flex justify-end space-x-2">
                <Button type="button" variant="outline" :disabled="form.processing" @click="$inertia.visit('/users')">
                  Cancel
                </Button>
                <Button type="submit" :disabled="form.processing">
                  Save Changes
                </Button>
              </div>
            </Form>
          </CardContent>
        </Card>
      </div>
    </div>
  </AppLayout>
</template>
