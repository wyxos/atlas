<script setup lang="ts">
import PageLayout from '../components/PageLayout.vue';
import { ref } from 'vue';

// Password form
const passwordForm = ref({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const passwordErrors = ref<Record<string, string>>({});
const passwordSuccess = ref('');
const passwordLoading = ref(false);

// Delete account form
const deleteForm = ref({
    password: '',
});

const deleteErrors = ref<Record<string, string>>({});
const deleteLoading = ref(false);

function clearPasswordErrors(): void {
    passwordErrors.value = {};
    passwordSuccess.value = '';
}

function clearDeleteErrors(): void {
    deleteErrors.value = {};
}

async function handlePasswordUpdate(): Promise<void> {
    clearPasswordErrors();
    passwordLoading.value = true;

    try {
        const response = await window.axios.post('/profile/password', passwordForm.value);

        passwordSuccess.value = response.data.message || 'Password updated successfully.';
        passwordForm.value = {
            current_password: '',
            password: '',
            password_confirmation: '',
        };
    } catch (error: unknown) {
        if (window.axios.isAxiosError(error) && error.response?.data?.errors) {
            passwordErrors.value = error.response.data.errors;
        } else {
            passwordErrors.value = {
                current_password: 'An error occurred. Please try again.',
            };
        }
    } finally {
        passwordLoading.value = false;
    }
}

async function handleAccountDeletion(): Promise<void> {
    if (!confirm('Are you sure you want to delete your account? This action cannot be undone.')) {
        return;
    }

    clearDeleteErrors();
    deleteLoading.value = true;

    try {
        await window.axios.delete('/profile/account', {
            data: deleteForm.value,
        });

        // Account deleted, redirect to home
        window.location.href = '/';
    } catch (error: unknown) {
        if (window.axios.isAxiosError(error) && error.response?.data?.errors) {
            deleteErrors.value = error.response.data.errors;
        } else {
            deleteErrors.value = {
                password: 'An error occurred. Please try again.',
            };
        }
    } finally {
        deleteLoading.value = false;
    }
}
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="text-center mb-8">
                <h4 class="text-2xl font-semibold mb-2 text-regal-navy-900">
                    Profile
                </h4>
                <p class="text-blue-slate-700">
                    Manage your account settings
                </p>
            </div>

            <!-- Password Reset Section -->
            <section class="mb-8">
                <div class="rounded-lg p-6 mb-6 bg-smart-blue-300 border-2 border-smart-blue-500">
                    <h2 class="text-xl font-semibold mb-4 text-smart-blue-900">
                        Change Password
                    </h2>

                    <form @submit.prevent="handlePasswordUpdate" class="space-y-4">
                        <div>
                            <label
                                for="current_password"
                                class="block text-sm font-medium mb-2 text-twilight-indigo-900"
                            >
                                Current Password
                            </label>
                            <input
                                id="current_password"
                                v-model="passwordForm.current_password"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors bg-prussian-blue-500 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-2 focus:ring-smart-blue-600 focus:ring-opacity-20"
                                :class="{
                                    'border-danger-700': passwordErrors.current_password,
                                }"
                                @focus="clearPasswordErrors"
                            />
                            <p
                                v-if="passwordErrors.current_password"
                                class="mt-1 text-sm text-danger-700"
                            >
                                {{ passwordErrors.current_password }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="password"
                                class="block text-sm font-medium mb-2 text-twilight-indigo-900"
                            >
                                New Password
                            </label>
                            <input
                                id="password"
                                v-model="passwordForm.password"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors bg-prussian-blue-500 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-2 focus:ring-smart-blue-600 focus:ring-opacity-20"
                                :class="{
                                    'border-danger-700': passwordErrors.password,
                                }"
                                @focus="clearPasswordErrors"
                            />
                            <p
                                v-if="passwordErrors.password"
                                class="mt-1 text-sm text-danger-700"
                            >
                                {{ passwordErrors.password }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="password_confirmation"
                                class="block text-sm font-medium mb-2 text-twilight-indigo-900"
                            >
                                Confirm New Password
                            </label>
                            <input
                                id="password_confirmation"
                                v-model="passwordForm.password_confirmation"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors bg-prussian-blue-500 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-2 focus:ring-smart-blue-600 focus:ring-opacity-20"
                            />
                        </div>

                        <div v-if="passwordSuccess" class="p-3 rounded-lg bg-smart-blue-300 border border-smart-blue-500">
                            <p class="text-sm text-smart-blue-700">
                                {{ passwordSuccess }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            :disabled="passwordLoading"
                            class="px-6 py-3 rounded-lg font-semibold transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed bg-smart-blue-500 text-white hover:bg-smart-blue-600 shadow-lg"
                        >
                            <span v-if="passwordLoading">Updating...</span>
                            <span v-else>Update Password</span>
                        </button>
                    </form>
                </div>
            </section>

            <!-- Delete Account Section -->
            <section>
                <div class="rounded-lg p-6 bg-danger-100 border-2 border-danger-600">
                    <h2 class="text-xl font-semibold mb-2 text-danger-900">
                        Delete Account
                    </h2>
                    <p class="mb-4 text-sm text-twilight-indigo-900">
                        Once you delete your account, there is no going back. Please be certain.
                    </p>

                    <form @submit.prevent="handleAccountDeletion" class="space-y-4">
                        <div>
                            <label
                                for="delete_password"
                                class="block text-sm font-medium mb-2 text-twilight-indigo-900"
                            >
                                Enter your password to confirm
                            </label>
                            <input
                                id="delete_password"
                                v-model="deleteForm.password"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors bg-danger-200 border-danger-400 text-twilight-indigo-900 focus:border-danger-600 focus:ring-2 focus:ring-danger-600 focus:ring-opacity-20"
                                :class="{
                                    'border-danger-700': deleteErrors.password,
                                }"
                                @focus="clearDeleteErrors"
                            />
                            <p
                                v-if="deleteErrors.password"
                                class="mt-1 text-sm text-danger-700"
                            >
                                {{ deleteErrors.password }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            :disabled="deleteLoading"
                            class="px-6 py-3 rounded-lg font-semibold transition-colors shadow-lg cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed bg-danger-600 text-white hover:bg-danger-700"
                        >
                            <span v-if="deleteLoading">Deleting...</span>
                            <span v-else>Delete Account</span>
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </PageLayout>
</template>
