<script setup lang="ts">
import * as UserController from '@/actions/App/Http/Controllers/UserController';
import AppLayout from '@/layouts/AppLayout.vue';
import ContentLayout from '@/layouts/ContentLayout.vue';
import ScrollableLayout from '@/layouts/ScrollableLayout.vue';
import SectionHeader from '@/components/audio/SectionHeader.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { OTable, OTableColumn } from '@oruga-ui/oruga-next';
import { Users } from 'lucide-vue-next';
import { ref } from 'vue';
import { useElementSize } from '@vueuse/core';

interface UserItem {
    id: number;
    name: string;
    email: string;
    created_at: string;
    last_login_at: string | null;
}

defineProps<{
    users: {
        data: UserItem[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: UserController.index().url }];

// Dynamic table height based on container
const tableContainerRef = ref<HTMLElement | null>(null);
const { height } = useElementSize(tableContainerRef);
const tableHeight = height;
</script>

<template>
    <Head title="Users" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <ContentLayout>
            <SectionHeader title="Users" :icon="Users" />
            <ScrollableLayout>
                <div ref="tableContainerRef" class="h-full">
                    <OTable :data="users.data" hoverable striped :paginated="false" :sticky-header="true" :height="tableHeight">
                        <OTableColumn field="id" label="ID" v-slot="props">
                            <span class="px-1 text-sm">{{ props.row.id }}</span>
                        </OTableColumn>
                        <OTableColumn field="name" label="Name" v-slot="props">
                            <span class="px-1 text-sm">{{ props.row.name }}</span>
                        </OTableColumn>
                        <OTableColumn field="email" label="Email" v-slot="props">
                            <span class="px-1 text-sm">{{ props.row.email }}</span>
                        </OTableColumn>
                        <OTableColumn field="created_at" label="Created" v-slot="props">
                            <span class="px-1 text-sm">{{ new Date(props.row.created_at).toLocaleString() }}</span>
                        </OTableColumn>
                        <OTableColumn field="last_login_at" label="Last Login" v-slot="props">
                            <span class="px-1 text-sm">
                                {{ props.row.last_login_at ? new Date(props.row.last_login_at).toLocaleString() : 'Never' }}
                            </span>
                        </OTableColumn>

                        <template #empty>
                            <div class="px-4 py-6 text-center text-sm text-muted-foreground">No users found.</div>
                        </template>
                    </OTable>
                </div>
            </ScrollableLayout>

            <!-- Simple pagination -->
            <div class="mt-4 flex items-center justify-between">
                <div class="text-sm text-muted-foreground">Page {{ users.current_page }} of {{ users.last_page }} — {{ users.total }} total</div>
                <div class="flex items-center gap-2">
                    <Link
                        :href="UserController.index({ mergeQuery: { page: Math.max(users.current_page - 1, 1) } }).url"
                        class="rounded-md border border-border px-3 py-1 text-sm hover:bg-accent"
                        :class="{ 'pointer-events-none opacity-50': users.current_page <= 1 }"
                    >
                        Previous
                    </Link>
                    <Link
                        :href="UserController.index({ mergeQuery: { page: Math.min(users.current_page + 1, users.last_page) } }).url"
                        class="rounded-md border border-border px-3 py-1 text-sm hover:bg-accent"
                        :class="{ 'pointer-events-none opacity-50': users.current_page >= users.last_page }"
                    >
                        Next
                    </Link>
                </div>
            </div>
        </ContentLayout>
    </AppLayout>
</template>
