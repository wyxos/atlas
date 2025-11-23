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
                    Dashboard
                </h1>
                <p style="color: #a0aecb;">
                    Welcome to your dashboard
                </p>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <router-link
                    to="/users"
                    class="block rounded-lg p-6 transition-colors"
                    style="background-color: #023d78; border: 2px solid #0466c8;"
                    @mouseenter="handleHover($event, true)"
                    @mouseleave="handleHover($event, false)"
                >
                    <h2
                        class="text-xl font-semibold mb-3"
                        style="color: #c3e0fe;"
                    >
                        Users
                    </h2>
                    <p style="color: #d0d7e5;">
                        Manage users and permissions
                    </p>
                </router-link>

                <div
                    class="rounded-lg p-6"
                    style="background-color: #023263; border: 2px solid #0353a4;"
                >
                    <h2
                        class="text-xl font-semibold mb-3"
                        style="color: #bcddfe;"
                    >
                        Coming Soon
                    </h2>
                    <p style="color: #d0d7e5;">
                        More features coming soon
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
function handleHover(event: MouseEvent, isEntering: boolean): void {
    const target = event.currentTarget as HTMLElement;
    if (isEntering) {
        target.style.backgroundColor = '#0352a0';
    } else {
        target.style.backgroundColor = '#023d78';
    }
}
</script>

