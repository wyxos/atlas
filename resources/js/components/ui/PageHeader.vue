<script setup lang="ts">
interface Props {
    title: string;
    subtitle?: string;
    centered?: boolean;
}

withDefaults(defineProps<Props>(), {
    subtitle: '',
    centered: false,
});
</script>

<template>
    <div :class="{ 'text-center': centered }" class="mb-8">
        <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">
            {{ title }}
        </h4>
        <p v-if="subtitle" class="text-blue-slate-300">
            {{ subtitle }}
        </p>
    </div>
</template>



