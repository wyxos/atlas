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

// Create reactive listing instance (Listing.create returns a reactive Proxy)
// Proxy provides dynamic filter properties (e.g., listing.search, listing.date_from)
// TypeScript can't infer dynamic Proxy properties, so we cast to any
 
const listing = Listing.create<User>({
    filters: {
        search: '',
        date_from: '',
        date_to: '',
        status: 'all',
    },
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
}) as any;
listing.loading(); // Initial loading state

// Configure listing with path, router, filters, and error handler
listing
    .path('/api/users')
    .router(router)
    .onLoadError((error: string | null, statusCode?: number) => {
        // Customize error messages for users context
        if (statusCode === 403) {
            return 'You do not have permission to view users.';
        }
        if (error && error.includes('Failed to load data')) {
            return 'Failed to load users. Please try again later.';
        }
        return error;
    });

// Get current user ID from meta tag
const currentUserId = parseInt(document.querySelector('meta[name="user-id"]')?.getAttribute('content') || '0', 10);

const deletingUserId = ref<number | null>(null);
const dialogOpen = ref(false);
const userToDelete = ref<User | null>(null);
const deleteError = ref<string | null>(null);
const canRetryDelete = ref(false);

// Check if a user can be deleted (users cannot delete themselves)
function canDeleteUser(user: User): boolean {
    return user.id !== currentUserId;
}
// Panel visibility is tracked by the Listing instance; this computed bridges
// it to the FilterPanel's v-model.
const filterPanelOpen = computed({
    get() {
        return listing.isPanelOpen();
    },
    set(open: boolean) {
        if (open) {
            listing.openPanel();
        } else {
            listing.closePanel();
        }
    },
});


async function deleteUser(userId: number): Promise<void> {
    deletingUserId.value = userId;
    deleteError.value = null;
    canRetryDelete.value = false;

    await listing.delete(`/api/users/${userId}`, userId, {
        onSuccess: () => {
            dialogOpen.value = false;
            userToDelete.value = null;
            deletingUserId.value = null;
        },
        onError: (error: unknown, statusCode?: number) => {
            if (statusCode === 403) {
                deleteError.value = 'You do not have permission to delete users.';
                canRetryDelete.value = false;
            } else if (statusCode && statusCode >= 500) {
                deleteError.value = 'Something went wrong while deleting the user. Please try again.';
                canRetryDelete.value = true;
            } else {
                deleteError.value = 'Failed to delete user. Please try again later.';
                canRetryDelete.value = false;
            }

            console.error('Error deleting user:', error);
            deletingUserId.value = null;
        },
    });
}

function openDeleteDialog(user: User): void {
    userToDelete.value = user;
    dialogOpen.value = true;
    deleteError.value = null;
    canRetryDelete.value = false;
}

async function handleDeleteConfirm(): Promise<void> {
    if (userToDelete.value) {
        await deleteUser(userToDelete.value.id);
    }
}

function handleDeleteCancel(): void {
    dialogOpen.value = false;
    userToDelete.value = null;
    deleteError.value = null;
    canRetryDelete.value = false;
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

async function applyFilters(): Promise<void> {
    await listing.goToPage(1); // Reset to first page when applying filters
    listing.closePanel();
}

const hasActiveFilters = computed(() => {
    const search = listing.filters.search ?? '';
    const from = listing.filters.date_from ?? '';
    const to = listing.filters.date_to ?? '';
    const status = listing.filters.status ?? 'all';

    return String(search).trim() !== '' ||
        String(from) !== '' ||
        String(to) !== '' ||
        String(status) !== 'all';
});

// Watch for route query changes (back/forward navigation)
watch(() => route.query, async (newQuery) => {
    await listing.get({ query: newQuery });
}, { deep: true });

// Expose properties for testing
defineExpose({
    listing,
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
        return listing.isLoading;
    },
    get error() {
        return listing.error;
    },
    get activeFilters() {
        return listing.activeFilters;
    },
    applyFilters,
});

onMounted(async () => {
    await listing.get({ query: route.query });
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
                    @click="() => listing.openPanel()"
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
                        @click="() => listing.removeFilter(filter.key)"
                        variant="ghost"
                        size="sm"
                        class="flex items-center justify-center bg-danger-600 px-1.5 hover:bg-danger-700 text-white rounded-br rounded-tr border-0"
                        :aria-label="`Remove ${filter.label} filter`"
                    >
                        <X class="h-3.5 w-3.5" />
                    </Button>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    @click="() => listing.resetFilters()"
                    class="border-danger-600 text-danger-600 bg-transparent hover:bg-danger-300 hover:border-danger-600 hover:text-danger-600"
                >
                    Clear all
                </Button>
            </div>

            <div v-if="listing.isLoading" class="text-center py-12">
                <p class="text-twilight-indigo-900 text-lg">Loading users...</p>
            </div>

            <div v-else-if="listing.error" class="text-center py-12">
                <p class="text-red-500 text-lg">{{ listing.error }}</p>
            </div>

            <div v-else class="w-full overflow-x-auto">
                <o-table
                    :data="listing.data"
                    :loading="listing.isLoading"
                    paginated
                    :per-page="listing.perPage"
                    :current-page="listing.currentPage"
                    :total="listing.total"
                    backend-pagination
                    pagination-position="both"
                    pagination-order="right"
                    @page-change="(page: number) => listing.goToPage(page)"
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
                            v-if="canDeleteUser(row)"
                            @click="openDeleteDialog(row)"
                            variant="ghost"
                            size="sm"
                            class="p-2 border-2 border-danger-700 text-danger-700 bg-transparent hover:bg-danger-500 hover:border-danger-600 hover:text-danger-900"
                            :disabled="deletingUserId === row.id"
                        >
                            <Trash2 class="w-4 h-4" />
                        </Button>
                        <span
                            v-else
                            class="text-xs text-twilight-indigo-600 italic"
                            title="You cannot delete your own account"
                        >
                            —
                        </span>
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
                            @click="() => listing.resetFilters()"
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
                @reset="() => listing.resetFilters()"
            >
                <form @submit.prevent="applyFilters" class="space-y-6">
                    <!-- Search Field -->
                    <FormInput
                        v-model="listing.search"
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
                                    v-model="listing.date_from"
                                    placeholder="Pick start date"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-medium mb-1 text-twilight-indigo-700">
                                    To
                                </label>
                                <DatePicker
                                    v-model="listing.date_to"
                                    placeholder="Pick end date"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Status Filter -->
                    <Select v-model="listing.status">
                        <template #label>
                            Status
                        </template>
                        <option value="all">All</option>
                        <option value="verified">Verified</option>
                        <option value="unverified">Unverified</option>
                    </Select>
                </form>
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
                    <div v-if="deleteError" class="mt-4 rounded border border-danger-600 bg-danger-700/20 px-3 py-2 text-sm text-danger-300">
                        {{ deleteError }}
                    </div>
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
                            v-if="canRetryDelete || !deleteError"
                            @click="handleDeleteConfirm"
                            :disabled="deletingUserId !== null"
                            variant="default"
                            class="bg-danger-600 hover:bg-danger-700"
                        >
                            {{ deletingUserId !== null ? 'Deleting...' : (deleteError && canRetryDelete ? 'Retry' : 'Delete') }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </PageLayout>
</template>
