<script setup lang="ts">
import 'vue-sonner/style.css';
import { Toaster as SonnerToaster } from 'vue-sonner';
</script>

<template>
    <SonnerToaster
        theme="dark"
        position="bottom-right"
        :duration="Infinity"
        :visible-toasts="99"
        :close-button="true"
        :rich-colors="true"
        expand
    />
</template>
