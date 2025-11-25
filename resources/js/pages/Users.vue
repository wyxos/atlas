<script setup lang="ts">
import { ref, onMounted, watch, computed, reactive } from 'vue';
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
import Link from '../components/ui/Link.vue';
import { Listing } from '../lib/Listing';

const route = useRoute();
const router = useRouter();

interface User extends Record<string, unknown> {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    last_login_at: string | null;
    created_at: string;
}

// Filter state
const searchQuery = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const statusFilter = ref('all');

// Create reactive listing instance
const listing = reactive(new Listing<User>());
listing.setLoading(true); // Initial loading state

// Configure listing with path, router, filters, and error handler
listing
    .path('/api/users')
    .router(router)
    .filters({
        search: searchQuery,
        date_from: dateFrom,
        date_to: dateTo,
        status: statusFilter,
    })
    .onError((error: string | null, statusCode?: number) => {
        // Customize error messages for users context
        if (statusCode === 403) {
            return 'You do not have permission to view users.';
        }
        if (error && error.includes('Failed to load data')) {
            return 'Failed to load users. Please try again later.';
        }
        return error;
    });

const deletingUserId = ref<number | null>(null);
const dialogOpen = ref(false);
const userToDelete = ref<User | null>(null);
const filterPanelOpen = ref(false);

async function handlePageChange(page: number): Promise<void> {
    await listing.setPagination(page);
}

// updateUrl is now handled internally by the Listing class

async function loadFromUrl(): Promise<void> {
    const query = route.query;
    
    // Load filter values from URL first
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
    
    // Set pagination after filters are loaded (but don't auto-load yet)
    if (query.page) {
        const page = parseInt(String(query.page), 10);
        if (!isNaN(page) && page > 0) {
            await listing.setPagination(page, undefined, false); // Don't auto-load, listing.load() will be called separately
        }
    }
}

async function deleteUser(userId: number): Promise<void> {
    try {
        deletingUserId.value = userId;
        await window.axios.delete(`/api/users/${userId}`);
        
        // Close dialog first
        dialogOpen.value = false;
        userToDelete.value = null;
        
        // Remove the user from the listing
        listing.remove(userId);
        
        // If current page is empty and not page 1, go to previous page
        if (listing.data.length === 0 && listing.currentPage > 1) {
            await listing.setPagination(listing.currentPage - 1);
        } else {
            // Refresh the current page to get updated data
            await listing.load();
        }
    } catch (err: unknown) {
        const axiosError = err as { response?: { status?: number } };
        if (axiosError.response?.status === 403) {
            listing.error = 'You do not have permission to delete users.';
        } else {
            listing.error = 'Failed to delete user. Please try again later.';
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

async function applyFilters(): Promise<void> {
    await listing.setPagination(1); // Reset to first page when applying filters
    filterPanelOpen.value = false;
}

async function resetFilters(): Promise<void> {
    searchQuery.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    statusFilter.value = 'all';
    await listing.setPagination(1);
}

async function removeFilter(filterKey: string): Promise<void> {
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
    await listing.setPagination(1);
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
    listing.load();
}, { deep: true });

// Expose properties for testing
defineExpose({
    listing,
    searchQuery,
    dateFrom,
    dateTo,
    statusFilter,
    get currentPage() {
        return listing.currentPage;
    },
    get perPage() {
        return listing.perPage;
    },
    get total() {
        return listing.total;
    },
    get users() {
        return listing.data;
    },
    get loading() {
        return listing.loading;
    },
    get error() {
        return listing.error;
    },
    get activeFilters() {
        return listing.activeFilters;
    },
    applyFilters,
    resetFilters,
    handlePageChange,
});

onMounted(() => {
    loadFromUrl();
    listing.load();
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
            <div v-if="listing.activeFilters.length > 0" class="mb-6 flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-twilight-indigo-700">Active filters:</span>
                <div
                    v-for="filter in listing.activeFilters"
                    :key="filter.key"
                    class="inline-flex items-stretch rounded border border-smart-blue-600 text-sm"
                >
                    <span class="bg-smart-blue-600 px-3 py-1.5 font-medium text-white">{{ filter.label }}</span>
                    <span class="bg-smart-blue-300 px-3 py-1.5 text-smart-blue-900 truncate max-w-xs">{{ filter.value }}</span>
                    <Button
                        @click="removeFilter(filter.key)"
                        variant="ghost"
                        size="sm"
                        class="flex items-center justify-center bg-danger-600 px-1.5 hover:bg-danger-700 text-white rounded-br rounded-tr border-0"
                        :aria-label="`Remove ${filter.label} filter`"
                    >
                        <X class="h-3.5 w-3.5" />
                    </Button>
                </div>
                <Link
                    href="#"
                    variant="no-underline"
                    @click.prevent="resetFilters"
                    class="text-sm"
                >
                    Clear all
                </Link>
            </div>

            <div v-if="listing.loading" class="text-center py-12">
                <p class="text-twilight-indigo-900 text-lg">Loading users...</p>
            </div>

            <div v-else-if="listing.error" class="text-center py-12">
                <p class="text-red-500 text-lg">{{ listing.error }}</p>
            </div>

            <div v-else class="w-full overflow-x-auto">
                <o-table
                    :data="listing.data"
                    :loading="listing.loading"
                    paginated
                    :per-page="listing.perPage"
                    :current-page="listing.currentPage"
                    :total="listing.total"
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
                        <Button
                            @click="openDeleteDialog(row)"
                            variant="ghost"
                            size="sm"
                            class="p-2 border-2 border-danger-700 text-danger-700 bg-transparent hover:bg-danger-500 hover:border-danger-600 hover:text-danger-900"
                            :disabled="deletingUserId === row.id"
                        >
                            <Trash2 class="w-4 h-4" />
                        </Button>
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
                        <Button
                            @click="handleDeleteConfirm"
                            :disabled="deletingUserId !== null"
                            variant="default"
                            class="bg-danger-600 hover:bg-danger-700"
                        >
                            {{ deletingUserId !== null ? 'Deleting...' : 'Delete' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </PageLayout>
</template>
