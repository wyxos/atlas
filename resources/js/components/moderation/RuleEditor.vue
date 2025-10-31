<script setup lang="ts">
import { computed } from 'vue'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

// Ensure recursive self-reference works in <script setup>
 
defineOptions({ name: 'RuleEditor' })

export type RuleNode = {
  op: 'any' | 'all' | 'not_any' | 'at_least' | 'and' | 'or'
  terms?: string[] | null
  min?: number | null
  options?: { case_sensitive?: boolean; whole_word?: boolean } | null
  children?: RuleNode[] | null
}

const props = defineProps<{
  modelValue: RuleNode
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: RuleNode): void
}>()

const node = computed<RuleNode>({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

function update<K extends keyof RuleNode>(key: K, value: RuleNode[K]) {
  emit('update:modelValue', { ...node.value, [key]: value })
}

function addTerm() {
  const list = Array.isArray(node.value.terms) ? [...node.value.terms] : []
  list.push('')
  update('terms', list)
}

function updateTerm(idx: number, value: string) {
  const list = Array.isArray(node.value.terms) ? [...node.value.terms] : []
  list[idx] = value
  update('terms', list)
}

function removeTerm(idx: number) {
  const list = Array.isArray(node.value.terms) ? [...node.value.terms] : []
  list.splice(idx, 1)
  update('terms', list)
}

function toggleOption(name: 'case_sensitive' | 'whole_word', checked: boolean) {
  const opts = { ...(node.value.options || {}) }
  opts[name] = checked
  // prune empties
  if (opts.case_sensitive === false) delete (opts as any).case_sensitive
  if (opts.whole_word === true) delete (opts as any).whole_word
  const final = Object.keys(opts).length ? opts : null
  update('options', final || null)
}

function addChild() {
  const children = Array.isArray(node.value.children) ? [...node.value.children] : []
  children.push({ op: 'any', terms: [], min: null, options: { case_sensitive: false, whole_word: true }, children: null })
  update('children', children)
}

function updateChild(idx: number, child: RuleNode) {
  const children = Array.isArray(node.value.children) ? [...node.value.children] : []
  children[idx] = child
  update('children', children)
}

function removeChild(idx: number) {
  const children = Array.isArray(node.value.children) ? [...node.value.children] : []
  children.splice(idx, 1)
  update('children', children)
}
</script>

<template>
  <div class="grid gap-3">
    <div class="grid gap-1">
      <Label>Operator</Label>
      <select
        class="h-9 rounded-md border px-2 text-sm dark:bg-neutral-900"
        :value="node.op"
        @change="(e: any) => update('op', String(e?.target?.value) as any)"
      >
        <option value="any">any</option>
        <option value="all">all</option>
        <option value="not_any">not_any</option>
        <option value="at_least">at_least</option>
        <option value="and">and</option>
        <option value="or">or</option>
      </select>
    </div>

    <div v-if="node.op === 'at_least'" class="grid gap-1">
      <Label>Minimum matches</Label>
      <Input type="number" :modelValue="node.min ?? ''" @update:modelValue="(val: any) => update('min', val === '' || val == null ? null : Number(val))" />
    </div>

    <div v-if="node.op === 'any' || node.op === 'all' || node.op === 'not_any' || node.op === 'at_least'" class="grid gap-2">
      <div class="flex items-center justify-between">
        <Label>Terms</Label>
        <Button variant="outline" size="sm" @click="addTerm">Add term</Button>
      </div>
      <div class="grid gap-2">
        <div v-for="(t, idx) in (node.terms || [])" :key="idx" class="flex items-center gap-2">
          <Input class="flex-1" :modelValue="t" placeholder="term"
                 @update:modelValue="(val: any) => updateTerm(idx, String(val ?? ''))" />
          <Button variant="secondary" size="sm" @click="() => removeTerm(idx)">Remove</Button>
        </div>
      </div>
    </div>

    <div class="grid gap-2">
      <Label>Options</Label>
      <div class="flex items-center gap-4">
        <label class="inline-flex items-center gap-2 text-sm">
          <input type="checkbox" :checked="!!(node.options?.case_sensitive)" @change="(e: any) => toggleOption('case_sensitive', !!e?.target?.checked)" />
          <span>case_sensitive</span>
        </label>
        <label class="inline-flex items-center gap-2 text-sm">
          <input type="checkbox" :checked="node.options?.whole_word !== false" @change="(e: any) => toggleOption('whole_word', !!e?.target?.checked)" />
          <span>whole_word</span>
        </label>
      </div>
    </div>

    <div v-if="node.op === 'and' || node.op === 'or'" class="grid gap-2">
      <div class="flex items-center justify-between">
        <Label>Children</Label>
        <Button variant="outline" size="sm" @click="addChild">Add child</Button>
      </div>
      <div class="grid gap-3">
        <div v-for="(child, idx) in (node.children || [])" :key="idx" class="rounded-md border p-3">
          <div class="flex items-center justify-between mb-2">
            <div class="text-xs text-muted-foreground">Child {{ idx + 1 }}</div>
            <Button variant="secondary" size="sm" @click="() => removeChild(idx)">Remove</Button>
          </div>
          <RuleEditor :modelValue="(node.children || [])[idx]" @update:modelValue="(v: RuleNode) => updateChild(idx, v)" />
        </div>
      </div>
    </div>
  </div>
</template>