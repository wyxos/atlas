<script setup lang="ts">
import PageLayout from '../components/PageLayout.vue';
import FormInput from '../components/ui/FormInput.vue';
import Button from '../components/ui/Button.vue';
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
                <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">
                    Profile
                </h4>
                <p class="text-blue-slate-300">
                    Manage your account settings
                </p>
            </div>

            <!-- Password Reset Section -->
            <section class="mb-8">
                <div class="rounded-lg p-6 mb-6 bg-smart-blue-700 border-2 border-smart-blue-500">
                    <h2 class="text-xl font-semibold mb-4 text-smart-blue-100">
                        Change Password
                    </h2>

                    <form @submit.prevent="handlePasswordUpdate" class="space-y-4">
                        <FormInput
                            id="current_password"
                            v-model="passwordForm.current_password"
                            type="password"
                            required
                            :error="passwordErrors.current_password"
                            @focus="clearPasswordErrors"
                        >
                            <template #label>Current Password</template>
                        </FormInput>

                        <FormInput
                            id="password"
                            v-model="passwordForm.password"
                            type="password"
                            required
                            :error="passwordErrors.password"
                            @focus="clearPasswordErrors"
                        >
                            <template #label>New Password</template>
                        </FormInput>

                        <FormInput
                            id="password_confirmation"
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            required
                        >
                            <template #label>Confirm New Password</template>
                        </FormInput>

                        <div v-if="passwordSuccess" class="p-3 rounded-lg bg-smart-blue-700 border border-smart-blue-500">
                            <p class="text-sm text-smart-blue-300">
                                {{ passwordSuccess }}
                            </p>
                        </div>

                        <Button
                            type="submit"
                            :disabled="passwordLoading"
                            variant="default"
                            class="bg-smart-blue-500 hover:bg-smart-blue-400"
                        >
                            <span v-if="passwordLoading">Updating...</span>
                            <span v-else>Update Password</span>
                        </Button>
                    </form>
                </div>
            </section>

            <!-- Delete Account Section -->
            <section>
                <div class="rounded-lg p-6 bg-danger-900 border-2 border-danger-400">
                    <h2 class="text-xl font-semibold mb-2 text-danger-100">
                        Delete Account
                    </h2>
                    <p class="mb-4 text-sm text-twilight-indigo-100">
                        Once you delete your account, there is no going back. Please be certain.
                    </p>

                    <form @submit.prevent="handleAccountDeletion" class="space-y-4">
                        <FormInput
                            id="delete_password"
                            v-model="deleteForm.password"
                            type="password"
                            required
                            :error="deleteErrors.password"
                            @focus="clearDeleteErrors"
                        >
                            <template #label>Enter your password to confirm</template>
                        </FormInput>

                        <Button
                            type="submit"
                            :disabled="deleteLoading"
                            variant="default"
                            class="bg-danger-600 hover:bg-danger-700"
                        >
                            <span v-if="deleteLoading">Deleting...</span>
                            <span v-else>Delete Account</span>
                        </Button>
                    </form>
                </div>
            </section>
        </div>
    </PageLayout>
</template>
