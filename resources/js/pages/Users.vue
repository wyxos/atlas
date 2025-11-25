<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Trash2, CheckCircle2, Filter, Users, X } from 'lucide-vue-next';
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
import FilterPanel from '../components/ui/FilterPanel.vue';
import FormInput from '../components/ui/FormInput.vue';
import Select from '../components/ui/Select.vue';
import DatePicker from '../components/ui/DatePicker.vue';

const route = useRoute();
const router = useRouter();

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
const filterPanelOpen = ref(false);
const activeFilters = ref<Array<{ key: string; label: string; rawValue: string; value: string }>>([]);

// Filter state
const searchQuery = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const statusFilter = ref('all');

async function fetchUsers(): Promise<void> {
    try {
        loading.value = true;
        error.value = null;
        const params: Record<string, string | number> = {
            page: currentPage.value,
            per_page: perPage.value,
        };

        // Add filter parameters
        if (searchQuery.value.trim()) {
            params.search = searchQuery.value.trim();
        }
        if (dateFrom.value) {
            params.date_from = dateFrom.value;
        }
        if (dateTo.value) {
            params.date_to = dateTo.value;
        }
        if (statusFilter.value !== 'all') {
            params.status = statusFilter.value;
        }

        const response = await window.axios.get('/api/users', { params });
        // Harmonie format: response.data.listing.items
        const listing = response.data.listing || {};
        users.value = listing.items || [];
        currentPage.value = listing.current_page ?? 1;
        total.value = listing.total ?? 0;
        perPage.value = listing.perPage ?? 15;
        
        // Store active filters for display
        activeFilters.value = response.data.filters || [];
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
    updateUrl();
    fetchUsers();
}

function updateUrl(): void {
    const query: Record<string, string> = {};
    
    if (currentPage.value > 1) {
        query.page = String(currentPage.value);
    }
    
    if (searchQuery.value.trim()) {
        query.search = searchQuery.value.trim();
    }
    
    if (dateFrom.value) {
        query.date_from = dateFrom.value;
    }
    
    if (dateTo.value) {
        query.date_to = dateTo.value;
    }
    
    if (statusFilter.value !== 'all') {
        query.status = statusFilter.value;
    }
    
    router.push({ query }).catch(() => {
        // Ignore navigation errors (e.g., navigating to same route)
    });
}

function loadFromUrl(): void {
    const query = route.query;
    
    if (query.page) {
        const page = parseInt(String(query.page), 10);
        if (!isNaN(page) && page > 0) {
            currentPage.value = page;
        }
    }
    
    if (query.search) {
        searchQuery.value = String(query.search);
    }
    
    if (query.date_from) {
        dateFrom.value = String(query.date_from);
    }
    
    if (query.date_to) {
        dateTo.value = String(query.date_to);
    }
    
    if (query.status && ['verified', 'unverified'].includes(String(query.status))) {
        statusFilter.value = String(query.status);
    }
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
        
        const listing = response.data.listing || {};
        const newUsers = listing.items || [];
        const newTotal = listing.total ?? 0;
        const newCurrentPage = listing.current_page ?? 1;
        
        // If current page is empty and not page 1, go to previous page
        if (newUsers.length === 0 && currentPage.value > 1) {
            currentPage.value = currentPage.value - 1;
            await fetchUsers();
        } else {
            // Update with new data
            users.value = newUsers;
            currentPage.value = newCurrentPage;
            total.value = newTotal;
            activeFilters.value = response.data.filters || [];
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
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

function openFilterPanel(): void {
    filterPanelOpen.value = true;
}

function applyFilters(): void {
    currentPage.value = 1; // Reset to first page when applying filters
    updateUrl();
    fetchUsers();
    filterPanelOpen.value = false;
}

function resetFilters(): void {
    searchQuery.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    statusFilter.value = 'all';
    currentPage.value = 1;
    updateUrl();
    fetchUsers();
}

function removeFilter(filterKey: string): void {
    switch (filterKey) {
        case 'search':
            searchQuery.value = '';
            break;
        case 'date_from':
            dateFrom.value = '';
            break;
        case 'date_to':
            dateTo.value = '';
            break;
        case 'status':
            statusFilter.value = 'all';
            break;
    }
    currentPage.value = 1;
    updateUrl();
    fetchUsers();
}

const hasActiveFilters = computed(() => {
    return searchQuery.value.trim() !== '' || 
           dateFrom.value !== '' || 
           dateTo.value !== '' || 
           statusFilter.value !== 'all';
});

// Watch for route query changes (back/forward navigation)
watch(() => route.query, () => {
    loadFromUrl();
    fetchUsers();
}, { deep: true });

onMounted(() => {
    loadFromUrl();
    fetchUsers();
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h4 class="text-2xl font-semibold mb-2 text-regal-navy-900">
                        Users
                    </h4>
                    <p class="text-blue-slate-700">
                        Manage your users
                    </p>
                </div>
                <Button
                    variant="outline"
                    @click="openFilterPanel"
                    class="border-smart-blue-600 text-smart-blue-600 bg-transparent hover:bg-smart-blue-300 hover:border-smart-blue-600 hover:text-smart-blue-900"
                >
                    <Filter class="w-4 h-4 mr-2" />
                    Filters
                </Button>
            </div>

            <!-- Active Filters Display -->
            <div v-if="activeFilters.length > 0" class="mb-6 flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-twilight-indigo-700">Active filters:</span>
                <div
                    v-for="filter in activeFilters"
                    :key="filter.key"
                    class="inline-flex items-stretch rounded border border-smart-blue-600 text-sm"
                >
                    <span class="bg-smart-blue-600 px-3 py-1.5 font-medium text-white">{{ filter.label }}</span>
                    <span class="bg-smart-blue-300 px-3 py-1.5 text-smart-blue-900 truncate max-w-xs">{{ filter.value }}</span>
                    <button
                        @click="removeFilter(filter.key)"
                        class="flex items-center justify-center bg-danger-600 px-1.5 transition-colors hover:bg-danger-700 text-white rounded-br rounded-tr"
                        :aria-label="`Remove ${filter.label} filter`"
                    >
                        <X class="h-3.5 w-3.5" />
                    </button>
                </div>
                <button
                    @click="resetFilters"
                    class="text-sm font-medium text-smart-blue-600 underline hover:text-smart-blue-700"
                >
                    Clear all
                </button>
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
                <template #empty>
                    <div class="flex flex-col items-center justify-center py-12 px-6">
                        <Users class="w-16 h-16 text-twilight-indigo-600 mb-4" />
                        <h3 class="text-xl font-semibold text-regal-navy-900 mb-2">
                            {{ hasActiveFilters ? 'No users found' : 'No users yet' }}
                        </h3>
                        <p class="text-twilight-indigo-700 text-center max-w-md">
                            {{ hasActiveFilters 
                                ? 'Try adjusting your filters to see more results.' 
                                : 'Get started by creating your first user.' }}
                        </p>
                        <Button
                            v-if="hasActiveFilters"
                            variant="outline"
                            @click="resetFilters"
                            class="mt-4 border-smart-blue-600 text-smart-blue-600 bg-transparent hover:bg-smart-blue-300 hover:border-smart-blue-600 hover:text-smart-blue-900"
                        >
                            Clear Filters
                        </Button>
                    </div>
                </template>
                </o-table>
            </div>

            <!-- Filter Panel -->
            <FilterPanel
                v-model="filterPanelOpen"
                title="Filter Users"
                @apply="applyFilters"
                @reset="resetFilters"
            >
                <div class="space-y-6">
                    <!-- Search Field -->
                    <FormInput
                        v-model="searchQuery"
                        placeholder="Search by name or email..."
                    >
                        <template #label>
                            Search
                        </template>
                    </FormInput>

                    <!-- Date Range -->
                    <div>
                        <label class="block text-sm font-medium mb-2 text-smart-blue-900">
                            Created Date Range
                        </label>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium mb-1 text-twilight-indigo-700">
                                    From
                                </label>
                                <DatePicker
                                    v-model="dateFrom"
                                    placeholder="Pick start date"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1 text-twilight-indigo-700">
                                    To
                                </label>
                                <DatePicker
                                    v-model="dateTo"
                                    placeholder="Pick end date"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Status Filter -->
                    <Select v-model="statusFilter">
                        <template #label>
                            Status
                        </template>
                        <option value="all">All</option>
                        <option value="verified">Verified</option>
                        <option value="unverified">Unverified</option>
                    </Select>
                </div>
            </FilterPanel>

            <!-- Delete Confirmation Dialog -->
            <Dialog v-model="dialogOpen">
                <DialogContent class="sm:max-w-[425px] bg-prussian-blue-500 border-danger-500/30">
                    <DialogHeader>
                        <DialogTitle class="text-danger-600">Delete User</DialogTitle>
                        <DialogDescription class="text-base mt-2 text-twilight-indigo-900">
                            Are you sure you want to delete <span class="font-semibold text-danger-600">{{ userToDelete?.name }}</span>? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button 
                                variant="outline" 
                                @click="handleDeleteCancel" 
                                :disabled="deletingUserId !== null"
                                class="border-twilight-indigo-500 text-twilight-indigo-900 hover:bg-smart-blue-300 hover:border-smart-blue-600 hover:text-smart-blue-900"
                            >
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
