<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from '@/layouts/settings/Layout.vue'
import HeadingSmall from '@/components/HeadingSmall.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { type BreadcrumbItem } from '@/types'
import axios from 'axios'
import { ref, computed } from 'vue'
interface ModerationRule {
  id?: number
  name?: string | null
  type: 'contains' | 'contains-combo'
  terms: string[]
  match?: 'any' | 'all'
  unless?: string[] | null
  with_terms?: string[] | null
  action: 'block' | 'flag' | 'warn'
  active: boolean
  description?: string | null
}

const breadcrumbItems: BreadcrumbItem[] = [
  { title: 'Moderation rules', href: '/settings/moderation' },
]

const page = usePage()
const initial = page.props.rules as ModerationRule[]
const rules = ref<ModerationRule[]>([...initial])

const emptyRule = (): ModerationRule => ({
  name: '',
  type: 'contains',
  terms: [],
  match: 'any',
  unless: [],
  with_terms: [],
  action: 'block',
  active: true,
  description: '',
})

const editing = ref<ModerationRule | null>(null)
const form = ref<ModerationRule>(emptyRule())

function resetForm() {
  form.value = emptyRule()
  editing.value = null
}

function toArray(value: string): string[] {
  return value
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s.length > 0)
}

const termsCSV = computed({
  get: () => (form.value.terms ?? []).join(', '),
  set: (v: string) => (form.value.terms = toArray(v)),
})
const unlessCSV = computed({
  get: () => (form.value.unless ?? []).join(', '),
  set: (v: string) => (form.value.unless = toArray(v)),
})
const withCSV = computed({
  get: () => (form.value.with_terms ?? []).join(', '),
  set: (v: string) => (form.value.with_terms = toArray(v)),
})

async function save() {
  const payload = { ...form.value }
  try {
    if (editing.value?.id) {
      await axios.put(`/settings/moderation/${editing.value.id}`, payload)
    } else {
      await axios.post('/settings/moderation', payload)
    }
    await refresh()
    resetForm()
  } catch (e) {
    // Validation errors are flashed via Inertia; rely on server for now
  }
}

async function edit(rule: ModerationRule) {
  editing.value = { ...rule }
  form.value = { ...rule }
}

async function remove(rule: ModerationRule) {
  if (!rule.id) return
  await axios.delete(`/settings/moderation/${rule.id}`)
  await refresh()
}

async function refresh() {
  // Prefer Inertia to refresh props
  await axios.get('/settings/moderation').then((res) => {
    // When hit directly, server returns full HTML via Inertia. As a fallback, do a full reload.
    window.location.assign('/settings/moderation')
  })
}
</script>

<template>
  <AppLayout :breadcrumbs="breadcrumbItems">
    <Head title="Moderation rules" />

    <SettingsLayout>
      <div class="space-y-6">
        <HeadingSmall title="Moderation rules" description="Create, edit, and delete content moderation rules" />

        <form class="space-y-4" @submit.prevent="save">
          <div class="grid gap-2">
            <Label for="name">Name (optional)</Label>
            <Input id="name" v-model="form.name" placeholder="e.g. Block profanity" />
          </div>

          <div class="grid gap-2">
            <Label for="type">Type</Label>
            <select id="type" v-model="form.type" class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
              <option value="contains">contains</option>
              <option value="contains-combo">contains-combo</option>
            </select>
          </div>

          <div class="grid gap-2">
            <Label for="terms">Terms (comma-separated)</Label>
            <Input id="terms" v-model="termsCSV" placeholder="word1, word2" />
          </div>

          <div v-if="form.type === 'contains'" class="grid gap-2">
            <Label for="match">Match</Label>
            <select id="match" v-model="form.match" class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
              <option value="any">any</option>
              <option value="all">all</option>
            </select>
          </div>

          <div v-if="form.type === 'contains'" class="grid gap-2">
            <Label for="unless">Unless (comma-separated)</Label>
            <Input id="unless" v-model="unlessCSV" placeholder="exception1, exception2" />
          </div>

          <div v-if="form.type === 'contains-combo'" class="grid gap-2">
            <Label for="with">With terms (comma-separated)</Label>
            <Input id="with" v-model="withCSV" placeholder="term1, term2" />
          </div>

          <div class="grid gap-2">
            <Label for="action">Action</Label>
            <select id="action" v-model="form.action" class="mt-1 block w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
              <option value="block">block</option>
              <option value="flag">flag</option>
              <option value="warn">warn</option>
            </select>
          </div>

<div class="flex items-center gap-3">
            <Checkbox id="active" v-model="form.active" />
            <Label for="active">Active</Label>
          </div>

          <div class="grid gap-2">
            <Label for="description">Description</Label>
            <textarea id="description" v-model="form.description" placeholder="Optional description" class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input flex min-h-24 w-full min-w-0 rounded-md border bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive"></textarea>
          </div>

          <div class="flex gap-2">
            <Button type="submit">{{ editing?.id ? 'Update rule' : 'Create rule' }}</Button>
            <Button type="button" variant="secondary" @click="resetForm">Clear</Button>
          </div>
        </form>

        <div class="space-y-2">
          <h3 class="text-sm font-medium">Existing rules</h3>
          <div v-if="rules.length === 0" class="text-sm text-muted-foreground">No rules yet.</div>
          <div v-else class="space-y-2">
            <div v-for="rule in rules" :key="rule.id" class="rounded-md border p-3">
              <div class="flex items-center justify-between">
                <div class="text-sm font-medium">{{ rule.name || '(untitled)' }}</div>
                <div class="flex gap-2">
                  <Button size="sm" variant="secondary" @click="edit(rule)">Edit</Button>
                  <Button size="sm" variant="destructive" @click="remove(rule)">Delete</Button>
                </div>
              </div>
              <div class="mt-2 text-xs text-muted-foreground">
                <div>Type: {{ rule.type }}</div>
                <div>Terms: {{ rule.terms?.join(', ') }}</div>
                <div v-if="rule.type === 'contains'">Match: {{ rule.match }}</div>
                <div v-if="rule.type === 'contains' && rule.unless?.length">Unless: {{ rule.unless?.join(', ') }}</div>
                <div v-if="rule.type === 'contains-combo' && rule.with_terms?.length">With: {{ rule.with_terms?.join(', ') }}</div>
                <div>Action: {{ rule.action }} | Active: {{ rule.active ? 'yes' : 'no' }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </SettingsLayout>
  </AppLayout>
</template>
