<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';
import { ContextMenu, ContextMenuItem } from '@imengyu/vue3-context-menu';
import useContextMenu from '@/composables/useContextMenu';

const { show } = useContextMenu();

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
        <context-menu
            v-model:show="show"
        >
            <context-menu-item label="Simple item" />
<!--            <context-menu-item label="Simple item" @click="onMenuClick(1)" />-->
<!--            <context-menu-separator />&lt;!&ndash;use this to add separator&ndash;&gt;-->
<!--            <context-menu-group label="Menu with child">-->
<!--                <context-menu-item label="Item1" @click="onMenuClick(2)" />-->
<!--                <context-menu-item label="Item2" @click="onMenuClick(3)" />-->
<!--                <context-menu-group label="Child with v-for 50">-->
<!--                    <context-menu-item v-for="index of 50" :key="index" :label="'Item3-'+index" @click="onLoopMenuClick(index)" />-->
<!--                </context-menu-group>-->
<!--            </context-menu-group>-->
        </context-menu>
    </AppLayout>
</template>
