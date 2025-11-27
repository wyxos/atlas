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
        toast: 'group toast group-[.toaster]:bg-prussian-blue-400 group-[.toaster]:border-twilight-indigo-500 group-[.toaster]:text-twilight-indigo-900 group-[.toaster]:shadow-xl',
        title: 'group-[.toast]:text-twilight-indigo-900 group-[.toast]:font-semibold',
        description: 'group-[.toast]:text-twilight-indigo-700',
        actionButton: 'group-[.toast]:bg-smart-blue-600 group-[.toast]:text-white group-[.toast]:hover:bg-smart-blue-700 group-[.toast]:border-0',
        cancelButton: 'group-[.toast]:bg-twilight-indigo-800 group-[.toast]:text-twilight-indigo-50 group-[.toast]:hover:bg-twilight-indigo-700 group-[.toast]:border-0',
        closeButton: 'group-[.toast]:text-twilight-indigo-700 group-[.toast]:hover:text-twilight-indigo-900 group-[.toast]:hover:bg-twilight-indigo-500/20',
        success: 'group-[.toast]:bg-success-300/20 group-[.toast]:border-success-500 group-[.toast]:text-success-300 group-[.toast]:[&>div]:text-success-300',
        error: 'group-[.toast]:bg-danger-300/20 group-[.toast]:border-danger-500 group-[.toast]:text-danger-300 group-[.toast]:[&>div]:text-danger-300',
        warning: 'group-[.toast]:bg-danger-400/20 group-[.toast]:border-danger-600 group-[.toast]:text-danger-800 group-[.toast]:[&>div]:text-danger-800',
        info: 'group-[.toast]:bg-smart-blue-300/20 group-[.toast]:border-smart-blue-500 group-[.toast]:text-smart-blue-300 group-[.toast]:[&>div]:text-smart-blue-300',
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
