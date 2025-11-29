<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Trash2, CheckCircle2, Filter, Users } from 'lucide-vue-next';
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
import { Button } from '@/components/ui/button';
import FilterPanel from '../components/ui/FilterPanel.vue';
import Select from '../components/ui/Select.vue';
import ListingFilterForm from '../components/ListingFilterForm.vue';
import ActiveFilters from '../components/ActiveFilters.vue';
import ListingTable from '../components/ListingTable.vue';
import { Listing } from '../lib/Listing';
import { DeletionHandler } from '../lib/DeletionHandler';
import { formatDate } from '../utils/date';

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

// Check if a user can be deleted (users cannot delete themselves)
function canDeleteUser(user: User): boolean {
    return user.id !== currentUserId;
}

const deletionHandler = DeletionHandler.create<User>(listing, {
    getDeleteUrl: (user) => `/api/users/${user.id}`,
    getId: (user) => user.id,
    permissionDeniedMessage: 'You do not have permission to delete users.',
    serverErrorMessage: 'Something went wrong while deleting the user. Please try again.',
    genericErrorMessage: 'Failed to delete user. Please try again later.',
});


const hasActiveFilters = computed(() => listing.hasActiveFilters);

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
    get hasActiveFilters() {
        return listing.hasActiveFilters;
    },
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
                    <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">
                        Users
                    </h4>
                    <p class="text-blue-slate-300">
                        Manage your users
                    </p>
                </div>
                <Button variant="outline" @click="() => listing.openPanel()">
                    <Filter :size="16" class="mr-2" />
                    Filters
                </Button>
            </div>

            <!-- Active Filters Display -->
            <ActiveFilters :listing="listing" />

            <div v-if="listing.isLoading" class="text-center py-12">
                <p class="text-twilight-indigo-100 text-lg">Loading users...</p>
            </div>

            <div v-else-if="listing.error" class="text-center py-12">
                <p class="text-red-500 text-lg">{{ listing.error }}</p>
            </div>

            <div v-else class="w-full overflow-x-auto">
                <ListingTable :listing="listing" class="w-full overflow-hidden">
                    <o-table-column field="id" label="ID" width="80" />
                    <o-table-column field="name" label="Name" />
                    <o-table-column field="email" label="Email" />
                    <o-table-column field="email_verified_at" label="Verified">
                        <template #default="{ row }">
                            <span v-if="row.email_verified_at"
                                class="inline-flex items-center justify-center p-1.5 rounded-sm bg-success-300 border border-success-500"
                                title="Verified">
                                <CheckCircle2 :size="16" />
                            </span>
                            <span v-else
                                class="px-3 py-1 rounded-sm text-xs font-medium bg-twilight-indigo-500 border border-twilight-indigo-600">
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
                            <Button v-if="canDeleteUser(row)" @click="deletionHandler.openDialog(row)" variant="ghost"
                                size="sm"
                                class="flex items-center justify-center h-16 w-16 md:h-10 md:w-10 rounded-lg bg-danger-600 border-2 border-danger-700 text-white hover:bg-danger-700"
                                :disabled="deletionHandler.isDeleting && deletionHandler.itemToDelete?.id === row.id">
                                <Trash2 :size="40" class="text-white block md:hidden" />
                                <Trash2 :size="28" class="text-white hidden md:block" />
                            </Button>
                            <span v-else class="text-xs text-twilight-indigo-600 italic"
                                title="You cannot delete your own account">
                                —
                            </span>
                        </template>
                    </o-table-column>
                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-12 px-6">
                            <Users :size="64" class="text-twilight-indigo-600 mb-4" />
                            <h3 class="text-xl font-semibold text-regal-navy-100 mb-2">
                                {{ hasActiveFilters ? 'No users found' : 'No users yet' }}
                            </h3>
                            <p class="text-twilight-indigo-300 text-center max-w-md">
                                {{ hasActiveFilters
                                    ? 'Try adjusting your filters to see more results.'
                                    : 'Get started by creating your first user.' }}
                            </p>
                            <Button v-if="hasActiveFilters" variant="outline" @click="() => listing.resetFilters()"
                                class="mt-4">
                                Clear Filters
                            </Button>
                        </div>
                    </template>
                </ListingTable>
            </div>

            <!-- Filter Panel -->
            <FilterPanel :modelValue="listing.isPanelOpen()"
                @update:modelValue="(open) => open ? listing.openPanel() : listing.closePanel()" title="Filter Users"
                :is-filtering="listing.isFiltering" :is-resetting="listing.isResetting"
                @apply="() => listing.applyFilters()" @reset="() => listing.resetFilters()">
                <ListingFilterForm :search="listing.search" :date-from="listing.date_from" :date-to="listing.date_to"
                    search-placeholder="Search by name or email..." @update:search="(value) => listing.search = value"
                    @update:date-from="(value) => listing.date_from = value"
                    @update:date-to="(value) => listing.date_to = value" @submit="listing.applyFilters()">
                    <!-- Status Filter -->
                    <Select v-model="listing.status">
                        <template #label>
                            Status
                        </template>
                        <option value="all">All</option>
                        <option value="verified">Verified</option>
                        <option value="unverified">Unverified</option>
                    </Select>
                </ListingFilterForm>
            </FilterPanel>

            <!-- Delete Confirmation Dialog -->
            <Dialog v-model="deletionHandler.dialogOpen">
                <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                    <DialogHeader>
                        <DialogTitle class="text-danger-600">Delete User</DialogTitle>
                        <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                            Are you sure you want to delete <span class="font-semibold text-danger-600">{{
                                deletionHandler.itemToDelete?.name }}</span>? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <div v-if="deletionHandler.deleteError"
                        class="mt-4 rounded border border-danger-600 bg-danger-700/20 px-3 py-2 text-sm text-danger-300">
                        {{ deletionHandler.deleteError }}
                    </div>
                    <DialogFooter>
                        <DialogClose as-child>
                            <Button variant="outline" @click="deletionHandler.closeDialog"
                                :disabled="deletionHandler.isDeleting">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button v-if="deletionHandler.canRetryDelete || !deletionHandler.deleteError"
                            @click="deletionHandler.delete" :disabled="deletionHandler.isDeleting"
                            variant="destructive">
                            {{ deletionHandler.isDeleting ? 'Deleting...' : (deletionHandler.deleteError &&
                                deletionHandler.canRetryDelete ? 'Retry' : 'Delete') }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </PageLayout>
</template>
