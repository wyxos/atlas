<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Download, Trash2, Loader2 } from 'lucide-vue-next';
import { reactive, computed } from 'vue';

interface PluginItem {
  packageName: string;
  shortName: string;
  version?: string | null;
  description?: string;
  packagistUrl?: string | null;
  repositoryUrl?: string | null;
  installed: boolean;
  available: boolean;
}

const props = defineProps<{ plugins: PluginItem[] }>();
const page = usePage();

// Track busy state per package
const busy = reactive<Record<string, boolean>>({});

// Global operation in progress (disables all buttons)
const operationInProgress = computed(() => Object.values(busy).some(v => v));

// User ID for Echo channel
const userId = computed(() => (page.props.auth as any)?.user?.id);

// Listen for composer operation progress via Echo
if ((window as any).Echo && userId.value) {
  console.log('[Plugins] Setting up Echo listener for user:', userId.value);
  (window as any).Echo.private(`App.Models.User.${userId.value}`)
    .listen('.composer.operation.progress', (e: any) => {
      console.log('[Plugins] Echo event received:', e);
      const { packageName, operation, status, message } = e;

      if (status === 'running' && message) {
        // Optionally show progress messages
        console.log(`[${operation}] ${packageName}: ${message}`);
      } else if (status === 'completed') {
        console.log(`✓ ${message || `${operation} completed for ${packageName}`}`);
        alert(`Success: ${message || `${operation} completed for ${packageName}`}`);
        busy[packageName] = false;
        router.reload({ only: ['plugins'], preserveScroll: true });
      } else if (status === 'failed') {
        console.error(`✗ ${message || `${operation} failed for ${packageName}`}`);
        alert(`Error: ${message || `${operation} failed for ${packageName}`}`);
        busy[packageName] = false;
        router.reload({ only: ['plugins'], preserveScroll: true });
      }
    });
} else {
  console.warn('[Plugins] Echo not available or user not found');
}

async function install(plugin: PluginItem) {
  busy[plugin.packageName] = true;

  router.post(
    '/settings/plugins/install',
    { package: plugin.packageName },
    {
      preserveScroll: true,
      onError: (errors) => {
        const errorMsg = Object.values(errors)[0] as string;
        alert(`Installation Error: ${errorMsg}`);
        busy[plugin.packageName] = false;
      },
      onFinish: () => {
        // If Echo is not available, reload and clear busy after a delay
        if (!(window as any).Echo) {
          setTimeout(() => {
            busy[plugin.packageName] = false;
            router.reload({ only: ['plugins'], preserveScroll: true });
          }, 2000);
        }
      },
    }
  );
}

async function installAll() {
  if (!confirm('Install all available plugins?')) return;

  const toInstall = props.plugins.filter(p => p.available && !p.installed);
  
  for (const plugin of toInstall) {
    await install(plugin);
    // Wait a bit between installations to avoid race conditions
    await new Promise(resolve => setTimeout(resolve, 500));
  }
}

async function uninstall(plugin: PluginItem) {
  if (!confirm(`Are you sure you want to uninstall ${plugin.shortName}?`)) {
    return;
  }

  busy[plugin.packageName] = true;

  router.post(
    '/settings/plugins/uninstall',
    { package: plugin.packageName },
    {
      preserveScroll: true,
      onError: (errors) => {
        const errorMsg = Object.values(errors)[0] as string;
        alert(`Uninstallation Error: ${errorMsg}`);
        busy[plugin.packageName] = false;
      },
      onFinish: () => {
        // If Echo is not available, reload and clear busy after a delay
        if (!(window as any).Echo) {
          setTimeout(() => {
            busy[plugin.packageName] = false;
            router.reload({ only: ['plugins'], preserveScroll: true });
          }, 2000);
        }
      },
    }
  );
}
</script>

<template>
  <AppLayout :breadcrumbs="[{ title: 'Plugins', href: '/settings/plugins' }]">
    <Head title="Plugins" />
    <SettingsLayout>
      <div class="space-y-6" data-test="plugins-page">
        <div class="flex items-center justify-between">
          <HeadingSmall title="Plugins" description="Manage Atlas plugins" />
          <Button
            v-if="plugins.some(p => p.available && !p.installed)"
            @click="installAll"
            :disabled="operationInProgress"
            size="sm"
          >
            <Download class="mr-2 h-4 w-4" />
            Install All
          </Button>
        </div>

        <div v-if="plugins.length === 0" class="text-sm text-muted-foreground">No plugins found.</div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="text-muted-foreground border-b">
              <tr>
                <th class="py-2 pr-4">Name</th>
                <th class="py-2 pr-4">Status</th>
                <th class="py-2 pr-4">Version</th>
                <th class="py-2 pr-4">Actions</th>
                <th class="py-2 pr-4">Links</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in plugins" :key="p.packageName" :data-test="`plugin-row-${p.shortName}`" class="border-b last:border-b-0">
                <td class="py-3 pr-4">
                  <div class="font-medium" data-test="plugin-name">{{ p.shortName }}</div>
                  <div v-if="p.description" class="text-xs text-muted-foreground">{{ p.description }}</div>
                </td>
                <td class="py-3 pr-4">
                  <div class="flex gap-2">
                    <span v-if="p.installed" class="inline-flex items-center rounded-full bg-green-500/10 px-2 py-1 text-xs font-medium text-green-400 ring-1 ring-inset ring-green-500/20">
                      Installed
                    </span>
                    <span v-if="p.available" class="inline-flex items-center rounded-full bg-blue-500/10 px-2 py-1 text-xs font-medium text-blue-400 ring-1 ring-inset ring-blue-500/20">
                      Available
                    </span>
                  </div>
                </td>
                <td class="py-3 pr-4" data-test="plugin-version">
                  <span v-if="p.version">{{ p.version }}</span>
                  <span v-else class="text-muted-foreground">—</span>
                </td>
                <td class="py-3 pr-4">
                  <div class="flex gap-2">
                    <Button
                      v-if="p.available && !p.installed"
                      size="sm"
                      :disabled="operationInProgress"
                      @click="install(p)"
                      data-test="plugin-install-btn"
                    >
                      <Loader2 v-if="busy[p.packageName]" class="mr-2 h-4 w-4 animate-spin" />
                      <Download v-else class="mr-2 h-4 w-4" />
                      Install
                    </Button>
                    <Button
                      v-if="p.installed"
                      size="sm"
                      variant="destructive"
                      :disabled="operationInProgress"
                      @click="uninstall(p)"
                      data-test="plugin-uninstall-btn"
                    >
                      <Loader2 v-if="busy[p.packageName]" class="mr-2 h-4 w-4 animate-spin" />
                      <Trash2 v-else class="mr-2 h-4 w-4" />
                      Uninstall
                    </Button>
                  </div>
                </td>
                <td class="py-3 pr-4 space-x-3">
                  <a v-if="p.packagistUrl" :href="p.packagistUrl" target="_blank" rel="noreferrer" class="text-xs underline" data-test="plugin-packagist-link">Packagist</a>
                  <a v-if="p.repositoryUrl" :href="p.repositoryUrl" target="_blank" rel="noreferrer" class="text-xs underline" data-test="plugin-repo-link">Repository</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>
