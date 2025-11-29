<script setup lang="ts">
interface Props {
    title?: string;
    id?: string;
}

withDefaults(defineProps<Props>(), {
    title: '',
    id: undefined,
});
</script>

<template>
    <section :id="id" class="mb-12">
        <h2 v-if="title" class="text-3xl font-bold text-smart-blue-100 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
            {{ title }}
        </h2>
        <slot />
    </section>
</template>
