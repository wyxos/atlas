<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "lucide-vue-next"
import { Toaster as Sonner } from "vue-sonner"
import { cn } from "@/lib/utils"

const props = defineProps<ToasterProps>()
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    theme="dark"
    rich-colors
    :toast-options="{
      classNames: {
        toast: 'group toast group-[.toaster]:bg-prussian-blue-600 group-[.toaster]:border-twilight-indigo-500 group-[.toaster]:text-twilight-indigo-100 group-[.toaster]:shadow-xl',
        title: 'group-[.toast]:text-twilight-indigo-100 group-[.toast]:font-semibold',
        description: 'group-[.toast]:text-twilight-indigo-300',
        actionButton: 'group-[.toast]:bg-smart-blue-400 group-[.toast]:text-white group-[.toast]:hover:bg-smart-blue-700 group-[.toast]:border-0',
        cancelButton: 'group-[.toast]:bg-twilight-indigo-200 group-[.toast]:text-twilight-indigo-50 group-[.toast]:hover:bg-twilight-indigo-300 group-[.toast]:border-0',
        closeButton: 'group-[.toast]:text-twilight-indigo-300 group-[.toast]:hover:text-twilight-indigo-100 group-[.toast]:hover:bg-twilight-indigo-500/20',
        success: 'group-[.toast]:bg-success-700/20 group-[.toast]:border-success-500 group-[.toast]:text-success-700 group-[.toast]:[&>div]:text-success-700',
        error: 'group-[.toast]:bg-danger-700/20 group-[.toast]:border-danger-500 group-[.toast]:text-danger-700 group-[.toast]:[&>div]:text-danger-700',
        warning: 'group-[.toast]:bg-danger-600/20 group-[.toast]:border-danger-400 group-[.toast]:text-danger-200 group-[.toast]:[&>div]:text-danger-200',
        info: 'group-[.toast]:bg-smart-blue-700/20 group-[.toast]:border-smart-blue-500 group-[.toast]:text-smart-blue-700 group-[.toast]:[&>div]:text-smart-blue-700',
      },
    }"
    v-bind="props"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>
