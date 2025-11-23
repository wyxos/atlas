<template>
    <div class="w-full max-w-4xl mx-auto">
        <div
            class="rounded-lg shadow-xl p-8 md:p-12"
            style="background-color: #000e29;"
        >
            <div class="text-center mb-8">
                <h1
                    class="text-3xl md:text-4xl font-bold mb-2"
                    style="color: #c3e0fe;"
                >
                    Profile
                </h1>
                <p style="color: #a0aecb;">
                    Manage your account settings
                </p>
            </div>

            <!-- Password Reset Section -->
            <section class="mb-8">
                <div
                    class="rounded-lg p-6 mb-6"
                    style="background-color: #023d78; border: 2px solid #0466c8;"
                >
                    <h2
                        class="text-xl font-semibold mb-4"
                        style="color: #c3e0fe;"
                    >
                        Change Password
                    </h2>

                    <form @submit.prevent="handlePasswordUpdate" class="space-y-4">
                        <div>
                            <label
                                for="current_password"
                                class="block text-sm font-medium mb-2"
                                style="color: #d0d7e5;"
                            >
                                Current Password
                            </label>
                            <input
                                id="current_password"
                                v-model="passwordForm.current_password"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors"
                                style="background-color: #001233; border-color: #33415c; color: #d0d7e5;"
                                :class="{
                                    'border-red-500': passwordErrors.current_password,
                                }"
                                @focus="clearPasswordErrors"
                            />
                            <p
                                v-if="passwordErrors.current_password"
                                class="mt-1 text-sm"
                                style="color: #ef4444;"
                            >
                                {{ passwordErrors.current_password }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="password"
                                class="block text-sm font-medium mb-2"
                                style="color: #d0d7e5;"
                            >
                                New Password
                            </label>
                            <input
                                id="password"
                                v-model="passwordForm.password"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors"
                                style="background-color: #001233; border-color: #33415c; color: #d0d7e5;"
                                :class="{
                                    'border-red-500': passwordErrors.password,
                                }"
                                @focus="clearPasswordErrors"
                            />
                            <p
                                v-if="passwordErrors.password"
                                class="mt-1 text-sm"
                                style="color: #ef4444;"
                            >
                                {{ passwordErrors.password }}
                            </p>
                        </div>

                        <div>
                            <label
                                for="password_confirmation"
                                class="block text-sm font-medium mb-2"
                                style="color: #d0d7e5;"
                            >
                                Confirm New Password
                            </label>
                            <input
                                id="password_confirmation"
                                v-model="passwordForm.password_confirmation"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors"
                                style="background-color: #001233; border-color: #33415c; color: #d0d7e5;"
                            />
                        </div>

                        <div v-if="passwordSuccess" class="p-3 rounded-lg" style="background-color: #023d78; border: 1px solid #0466c8;">
                            <p class="text-sm" style="color: #4ba3fb;">
                                {{ passwordSuccess }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            :disabled="passwordLoading"
                            class="px-6 py-3 rounded-lg font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background-color: #0466c8; color: #ffffff;"
                        >
                            <span v-if="passwordLoading">Updating...</span>
                            <span v-else>Update Password</span>
                        </button>
                    </form>
                </div>
            </section>

            <!-- Delete Account Section -->
            <section>
                <div
                    class="rounded-lg p-6"
                    style="background-color: #023263; border: 2px solid #dc2626;"
                >
                    <h2
                        class="text-xl font-semibold mb-2"
                        style="color: #fca5a5;"
                    >
                        Delete Account
                    </h2>
                    <p class="mb-4 text-sm" style="color: #d0d7e5;">
                        Once you delete your account, there is no going back. Please be certain.
                    </p>

                    <form @submit.prevent="handleAccountDeletion" class="space-y-4">
                        <div>
                            <label
                                for="delete_password"
                                class="block text-sm font-medium mb-2"
                                style="color: #d0d7e5;"
                            >
                                Enter your password to confirm
                            </label>
                            <input
                                id="delete_password"
                                v-model="deleteForm.password"
                                type="password"
                                required
                                class="w-full px-4 py-2 rounded-lg border-2 transition-colors"
                                style="background-color: #001233; border-color: #33415c; color: #d0d7e5;"
                                :class="{
                                    'border-red-500': deleteErrors.password,
                                }"
                                @focus="clearDeleteErrors"
                            />
                            <p
                                v-if="deleteErrors.password"
                                class="mt-1 text-sm"
                                style="color: #ef4444;"
                            >
                                {{ deleteErrors.password }}
                            </p>
                        </div>

                        <button
                            type="submit"
                            :disabled="deleteLoading"
                            class="px-6 py-3 rounded-lg font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background-color: #dc2626; color: #ffffff;"
                        >
                            <span v-if="deleteLoading">Deleting...</span>
                            <span v-else>Delete Account</span>
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup lang="ts">
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

