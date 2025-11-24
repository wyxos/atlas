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
                    class="w-full rounded-lg overflow-hidden bg-prussian-blue-600"
                >
                <o-table-column field="id" label="ID" width="80" />
                <o-table-column field="name" label="Name" />
                <o-table-column field="email" label="Email" />
                <o-table-column field="email_verified_at" label="Verified">
                    <template #default="{ row }">
                        <span
                            v-if="row.email_verified_at"
                            class="px-3 py-1 rounded-full text-xs font-medium bg-success-300 text-success-600 border border-success-400"
                        >
                            Verified
                        </span>
                        <span
                            v-else
                            class="px-3 py-1 rounded-full text-xs font-medium bg-twilight-indigo-500 text-twilight-indigo-700 border border-twilight-indigo-600"
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
                            @click="confirmDelete(row)"
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
        </div>
    </PageLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import PageLayout from '../components/PageLayout.vue';

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

async function fetchUsers(): Promise<void> {
    try {
        loading.value = true;
        error.value = null;
        const response = await window.axios.get('/api/users');
        users.value = response.data.data;
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

async function deleteUser(userId: number): Promise<void> {
    try {
        deletingUserId.value = userId;
        await window.axios.delete(`/api/users/${userId}`);
        users.value = users.value.filter((user) => user.id !== userId);
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

function confirmDelete(user: User): void {
    if (confirm(`Are you sure you want to delete ${user.name}? This action cannot be undone.`)) {
        deleteUser(user.id);
    }
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

