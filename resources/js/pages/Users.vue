<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Trash2, CheckCircle2 } from 'lucide-vue-next';
import PageLayout from '../components/PageLayout.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogClose,
} from '../components/ui/dialog';
import Button from '../components/ui/Button.vue';

interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    last_login_at: string | null;
    created_at: string;
}

const users = ref<User[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const deletingUserId = ref<number | null>(null);
const currentPage = ref(1);
const perPage = ref(15);
const total = ref(0);
const dialogOpen = ref(false);
const userToDelete = ref<User | null>(null);

async function fetchUsers(): Promise<void> {
    try {
        loading.value = true;
        error.value = null;
        const response = await window.axios.get('/api/users', {
            params: {
                page: currentPage.value,
                per_page: perPage.value,
            },
        });
        users.value = response.data.data;
        // Laravel pagination metadata is in the 'meta' object
        const meta = response.data.meta || {};
        currentPage.value = meta.current_page ?? response.data.current_page ?? 1;
        total.value = meta.total ?? response.data.total ?? 0;
    } catch (err: unknown) {
        const axiosError = err as { response?: { status?: number } };
        if (axiosError.response?.status === 403) {
            error.value = 'You do not have permission to view users.';
        } else {
            error.value = 'Failed to load users. Please try again later.';
        }
        console.error('Error fetching users:', err);
    } finally {
        loading.value = false;
    }
}

function handlePageChange(page: number): void {
    currentPage.value = page;
    fetchUsers();
}

async function deleteUser(userId: number): Promise<void> {
    try {
        deletingUserId.value = userId;
        await window.axios.delete(`/api/users/${userId}`);
        
        // Close dialog first
        dialogOpen.value = false;
        userToDelete.value = null;
        
        // Fetch the same page to refresh data
        const response = await window.axios.get('/api/users', {
            params: {
                page: currentPage.value,
                per_page: perPage.value,
            },
        });
        
        const newUsers = response.data.data;
        const meta = response.data.meta || {};
        const newTotal = meta.total ?? response.data.total ?? 0;
        const newCurrentPage = meta.current_page ?? response.data.current_page ?? 1;
        
        // If current page is empty and not page 1, go to previous page
        if (newUsers.length === 0 && currentPage.value > 1) {
            currentPage.value = currentPage.value - 1;
            await fetchUsers();
        } else {
            // Update with new data
            users.value = newUsers;
            currentPage.value = newCurrentPage;
            total.value = newTotal;
        }
    } catch (err: unknown) {
        const axiosError = err as { response?: { status?: number } };
        if (axiosError.response?.status === 403) {
            error.value = 'You do not have permission to delete users.';
        } else {
            error.value = 'Failed to delete user. Please try again later.';
        }
        console.error('Error deleting user:', err);
    } finally {
        deletingUserId.value = null;
    }
}

function openDeleteDialog(user: User): void {
    userToDelete.value = user;
    dialogOpen.value = true;
}

async function handleDeleteConfirm(): Promise<void> {
    if (userToDelete.value) {
        await deleteUser(userToDelete.value.id);
    }
}

function handleDeleteCancel(): void {
    dialogOpen.value = false;
    userToDelete.value = null;
}

function formatDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

onMounted(() => {
    fetchUsers();
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-8">
                <h4 class="text-2xl font-semibold mb-2 text-regal-navy-900">
                    Users
                </h4>
                <p class="text-blue-slate-700">
                    Manage your users
                </p>
            </div>

            <div v-if="loading" class="text-center py-12">
                <p class="text-twilight-indigo-900 text-lg">Loading users...</p>
            </div>

            <div v-else-if="error" class="text-center py-12">
                <p class="text-red-500 text-lg">{{ error }}</p>
            </div>

            <div v-else class="w-full overflow-x-auto">
                <o-table
                    :data="users"
                    :loading="loading"
                    paginated
                    :per-page="perPage"
                    :current-page="currentPage"
                    :total="total"
                    backend-pagination
                    pagination-position="both"
                    pagination-order="right"
                    @page-change="handlePageChange"
                    class="w-full rounded-lg overflow-hidden bg-prussian-blue-600"
                >
                <o-table-column field="id" label="ID" width="80" />
                <o-table-column field="name" label="Name" />
                <o-table-column field="email" label="Email" />
                <o-table-column field="email_verified_at" label="Verified">
                    <template #default="{ row }">
                        <span
                            v-if="row.email_verified_at"
                            class="inline-flex items-center justify-center p-1.5 rounded-sm bg-success-300 border border-success-500"
                            title="Verified"
                        >
                            <CheckCircle2 class="w-4 h-4" />
                        </span>
                        <span
                            v-else
                            class="px-3 py-1 rounded-sm text-xs font-medium bg-twilight-indigo-500 border border-twilight-indigo-600"
                        >
                            Unverified
                        </span>
                    </template>
                </o-table-column>
                <o-table-column field="last_login_at" label="Last Login">
                    <template #default="{ row }">
                        <span v-if="row.last_login_at">{{ formatDate(row.last_login_at) }}</span>
                        <span v-else class="text-slate-grey-700">Never</span>
                    </template>
                </o-table-column>
                <o-table-column field="created_at" label="Created At">
                    <template #default="{ row }">
                        {{ formatDate(row.created_at) }}
                    </template>
                </o-table-column>
                <o-table-column label="Actions">
                    <template #default="{ row }">
                        <button
                            @click="openDeleteDialog(row)"
                            class="p-2 rounded-lg border-2 transition-all cursor-pointer delete-button border-danger-700 text-danger-700 bg-transparent"
                            :class="{
                                'opacity-50 cursor-not-allowed': deletingUserId === row.id
                            }"
                            :disabled="deletingUserId === row.id"
                        >
                            <Trash2 class="w-4 h-4" />
                        </button>
                    </template>
                </o-table-column>
                </o-table>
            </div>

            <!-- Delete Confirmation Dialog -->
            <Dialog v-model="dialogOpen">
                <DialogContent class="sm:max-w-[425px]">
                    <DialogHeader>
                        <DialogTitle>Delete User</DialogTitle>
                        <DialogDescription class="text-base mt-2">
                            Are you sure you want to delete <span class="font-semibold text-smart-blue-400">{{ userToDelete?.name }}</span>? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button variant="outline" @click="handleDeleteCancel" :disabled="deletingUserId !== null">
                                Cancel
                            </Button>
                        </DialogClose>
                        <button
                            @click="handleDeleteConfirm"
                            :disabled="deletingUserId !== null"
                            class="inline-flex items-center justify-center rounded-lg px-6 py-3 font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-50 disabled:pointer-events-none text-white shadow-lg bg-danger-600 hover:bg-danger-700"
                        >
                            {{ deletingUserId !== null ? 'Deleting...' : 'Delete' }}
                        </button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </PageLayout>
</template>
