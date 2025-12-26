# Vue Frontend - Agent Guidelines

## Package Identity

**Purpose**: Vue 3 SPA frontend with TypeScript  
**Framework**: Vue 3 (Composition API), Vue Router, TypeScript  
**Styling**: Tailwind CSS v4  
**Build Tool**: Vite  
**Routing**: Vue Router (client-side) + Laravel Wayfinder (type-safe route generation)

---

## Setup & Run

### Commands
```bash
# Development server
npm run dev

# Production build
npm run build

# Type checking
npm run check

# Run tests
npm run test
npm run test:watch
npm run test:coverage

# Lint JavaScript/TypeScript
npm run check:js
```

---

## Patterns & Conventions

### File Organization

**Components** (`resources/js/components/`)
- ✅ DO: Use Composition API with `<script setup>` syntax
- ✅ DO: Colocate tests with components: `ComponentName.vue` + `ComponentName.test.ts`
- ✅ DO: Extract reusable UI components to `resources/js/components/ui/`
- ✅ DO: Use TypeScript for all components
- ✅ Example: `resources/js/components/AppHeader.vue` - header component with test
- ✅ Example: `resources/js/components/ui/Button/Button.vue` - reusable button component

**Pages** (`resources/js/pages/`)
- ✅ DO: Pages are route components, use Vue Router
- ✅ DO: Keep pages focused on layout and composition
- ✅ DO: Delegate logic to composables
- ✅ Example: `resources/js/pages/Browse.vue` - main browse page
- ✅ Example: `resources/js/pages/Dashboard.vue` - dashboard layout

**Composables** (`resources/js/composables/`)
- ✅ DO: Extract reusable logic to composables (prefix with `use`)
- ✅ DO: Return reactive state and methods
- ✅ DO: Colocate tests: `useFeature.ts` + `useFeature.test.ts`
- ✅ Example: `resources/js/composables/useBrowseService.ts` - browse logic
- ✅ Example: `resources/js/composables/useTabs.ts` - tab management

**Routes** (`resources/js/routes/`)
- ✅ DO: Use Laravel Wayfinder for type-safe route generation
- ✅ DO: Define routes in `resources/js/routes.ts`
- ✅ DO: Use Vue Router for client-side navigation
- ✅ Example: `resources/js/routes/index.ts` - Wayfinder-generated routes
- ✅ Example: `resources/js/routes.ts` - Vue Router route definitions

**Types** (`resources/js/types/`)
- ✅ DO: Define TypeScript interfaces and types
- ✅ DO: Use descriptive names matching backend models
- ✅ Example: `resources/js/types/file.ts` - File-related types
- ✅ Example: `resources/js/types/reaction.ts` - Reaction types

**Utils** (`resources/js/utils/`)
- ✅ DO: Pure utility functions (no Vue reactivity)
- ✅ DO: Colocate tests: `util.ts` + `util.test.ts`
- ✅ Example: `resources/js/utils/date.ts` - date utilities
- ✅ Example: `resources/js/utils/file.ts` - file utilities

### Naming Conventions

- **Components**: PascalCase (e.g., `FileViewer.vue`, `AppHeader.vue`)
- **Composables**: camelCase with `use` prefix (e.g., `useBrowseService.ts`)
- **Pages**: PascalCase matching route name (e.g., `Browse.vue`, `Dashboard.vue`)
- **Types**: camelCase files, PascalCase interfaces (e.g., `file.ts` with `File` interface)
- **Utils**: camelCase (e.g., `date.ts`, `file.ts`)

### Vue 3 Composition API

**✅ DO: Use `<script setup>` syntax**
```vue
<script setup lang="ts">
import { ref, computed } from 'vue'

const count = ref(0)
const doubled = computed(() => count.value * 2)
</script>
```

**✅ DO: Use TypeScript for props and emits**
```vue
<script setup lang="ts">
interface Props {
  title: string
  count?: number
}

const props = defineProps<Props>()
const emit = defineEmits<{
  update: [value: string]
}>()
</script>
```

**❌ DON'T: Use Options API**
```vue
<!-- ❌ DON'T: Options API -->
<script>
export default {
  data() { return {} },
  methods: {}
}
</script>
```

### Direct Consumption Pattern

**1. Direct Consumption - Use API responses as-is**
```typescript
// ✅ DO: Direct consumption
const { data } = await axios.get('/api/files')
files.value = data // Use as-is if backend returns correct format

// ✅ DO: Object spread for merging
state.value = { ...state.value, ...response.data }

// ❌ DON'T: Manual mapping when backend already provides correct format
const { data } = await axios.get('/api/files')
files.value = data.map(item => ({
  id: item.id,
  name: item.name, // Only map if backend doesn't return what you need
}))
```

**2. Backend Alignment - Update backend, don't transform frontend**
```typescript
// ❌ DON'T: Transform in frontend
const { data } = await axios.get('/api/files')
const queryParams = data.params // Renaming unnecessarily

// ✅ DO: Use backend response directly
const { data } = await axios.get('/api/files')
const params = data.params // Use what backend returns

// ✅ DO: If frontend needs different structure, update backend Resource
// Edit: app/Http/Resources/FileResource.php
// Not: Transform in resources/js/composables/useFiles.ts
```

**3. Minimal Code - No intermediate variables**
```typescript
// ❌ DON'T: Unnecessary intermediate variable
const response = await axios.get('/api/files')
const data = response.data
files.value = data

// ✅ DO: Direct assignment
const { data } = await axios.get('/api/files')
files.value = data

// ❌ DON'T: Manual field-by-field construction
const item = {
  id: data.id,
  name: data.name,
  path: data.path,
}

// ✅ DO: Object spread when possible
const item = { ...data }
```

**4. Simplicity Over Abstraction**
```typescript
// ❌ DON'T: Over-engineer with premature abstraction
class FileService {
  private adapter: Adapter
  private transformer: Transformer
  // ... complex abstraction for simple API call
}

// ✅ DO: Simple, direct code
const { data } = await axios.get('/api/files')
files.value = data

// ✅ DO: Add abstraction only when you have 3+ concrete use cases
```

**5. No Unnecessary Mappings**
```typescript
// ❌ DON'T: Map params to queryParams unnecessarily
const { params } = await axios.get('/api/browse')
const queryParams = params // Unnecessary rename

// ✅ DO: Use what backend returns
const { params } = await axios.get('/api/browse')
// Use params directly

// ✅ DO: If you need queryParams, update backend to return queryParams
// Edit: app/Http/Controllers/BrowseController.php
// Not: Map in resources/js/composables/useBrowseService.ts
```

### Tailwind CSS v4

**✅ DO: Use Tailwind utilities**
```vue
<div class="flex gap-4 p-6 bg-white dark:bg-gray-900">
  <button class="px-4 py-2 bg-blue-500 text-white rounded">Click</button>
</div>
```

**✅ DO: Use CSS-first theme configuration**
```css
/* resources/css/app.css */
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
```

**❌ DON'T: Use deprecated utilities**
- ❌ `bg-opacity-*` → ✅ `bg-black/*`
- ❌ `flex-shrink-*` → ✅ `shrink-*`
- ❌ `flex-grow-*` → ✅ `grow-*`

### Component Examples

**✅ DO: Component with props and emits**
See `resources/js/components/FileViewer.vue`:
- TypeScript props interface
- Composition API
- Tailwind styling

**✅ DO: Reusable UI component**
See `resources/js/components/ui/Button/Button.vue`:
- Exported from `index.ts`
- Variants using `class-variance-authority`
- TypeScript types

**✅ DO: Page component**
See `resources/js/pages/Browse.vue`:
- Uses composables for logic
- Focuses on layout
- Uses Vue Router

**✅ DO: Composable**
See `resources/js/composables/useBrowseService.ts`:
- Returns reactive state
- Exposes methods
- Handles API calls

---

## Touch Points / Key Files

### Application Entry
- **Main**: `resources/js/app.ts` - Vue app initialization, router setup
- **Bootstrap**: `resources/js/bootstrap.ts` - App initialization logic
- **Routes**: `resources/js/routes.ts` - Vue Router route definitions
- **App Root**: `resources/js/App.vue` - Root component

### Routing
- **Wayfinder Routes**: `resources/js/routes/index.ts` - Type-safe Laravel routes
- **Vue Routes**: `resources/js/routes.ts` - Client-side route definitions
- **Wayfinder Config**: `resources/js/wayfinder/index.ts` - Wayfinder setup

### Key Components
- **Layouts**: `resources/js/layouts/` - Page layouts (DashboardLayout, PublicLayout)
- **UI Components**: `resources/js/components/ui/` - Reusable UI components
- **Feature Components**: `resources/js/components/` - Feature-specific components
  - `FileViewer.vue` - File viewing component
  - `TabContent.vue` - Tab content with masonry layout
  - `BrowseStatusBar.vue` - Browse status display

### Key Composables
- `resources/js/composables/useBrowseService.ts` - Browse API integration
- `resources/js/composables/useTabs.ts` - Tab management
- `resources/js/composables/useFileViewer*.ts` - File viewer logic (multiple files)
- `resources/js/composables/useModerationRules.ts` - Moderation rules

### Types
- `resources/js/types/file.ts` - File types
- `resources/js/types/reaction.ts` - Reaction types
- `resources/js/types/moderation.ts` - Moderation types
- `resources/js/types/container-blacklist.ts` - Container blacklist types

### Utils
- `resources/js/utils/date.ts` - Date formatting
- `resources/js/utils/file.ts` - File utilities
- `resources/js/utils/reactionQueue.ts` - Reaction queue management
- `resources/js/utils/masonryInteractions.ts` - Masonry layout interactions

---

## JIT Index Hints

### Search Commands
```bash
# Find a Vue component
rg -n "export default.*ComponentName" resources/js/components
rg -n "<script setup" resources/js/components

# Find a composable
rg -n "export.*use[A-Z]" resources/js/composables

# Find a page
find resources/js/pages -name "*.vue"

# Find a type definition
rg -n "interface|type" resources/js/types

# Find a route definition
rg -n "path:" resources/js/routes.ts

# Find API calls
rg -n "axios\.(get|post|put|delete)" resources/js

# Find tests
find resources/js -name "*.test.ts"
```

### Component Discovery
```bash
# List all components
find resources/js/components -name "*.vue"

# List all composables
find resources/js/composables -name "*.ts" | grep -v test

# List all pages
find resources/js/pages -name "*.vue"
```

---

## Common Gotchas

1. **Import Alias**: Use `@/` for absolute imports (configured in `vite.config.js`)
   ```typescript
   import { useTabs } from '@/composables/useTabs'
   ```

2. **Vue Router**: Client-side routing only - Laravel serves the SPA view for all GET routes
   - API routes (POST/DELETE) are handled by Laravel
   - Vue Router handles client-side navigation

3. **Wayfinder Routes**: Generated from Laravel routes - don't edit manually
   - Use `wayfinder()` in templates: `wayfinder('route.name')`
   - Type-safe route generation

4. **Reactivity**: Use `ref()` for primitives, `reactive()` for objects
   ```typescript
   const count = ref(0) // ✅
   const state = reactive({ count: 0 }) // ✅
   ```

5. **Tailwind v4**: No `tailwind.config.js` - use CSS `@theme` directive
   - Configuration in `resources/css/app.css`

6. **Testing**: Use `@vue/test-utils` for component tests
   - Mock Vue Router: `createRouter({ history: createMemoryHistory() })`
   - See `resources/js/components/AppHeader.test.ts` for example

7. **Build**: Run `npm run build` after changes, or use `npm run dev` for HMR

---

## Pre-PR Checks

```bash
npm run check && npm run test && npm run build
```

---

## UI Component Library

### Location
- Components: `resources/js/components/ui/`
- Organized by component name (e.g., `button/Button.vue`, `dialog/Dialog.vue`)

### Usage
- Import from component directories: `import Button from '@/components/ui/button/Button.vue'`
- Some components have `index.ts` exports for convenience

### Design System
- Uses Tailwind CSS v4 for styling
- Dark mode support via `dark:` utilities
- Components use `class-variance-authority` for variants (see Button component)

### Examples
- **Button**: `resources/js/components/ui/button/Button.vue`
- **Dialog**: `resources/js/components/ui/dialog/Dialog.vue`
- **Select**: `resources/js/components/ui/select/Select.vue`
- **Sheet**: `resources/js/components/ui/sheet/Sheet.vue`

---

## External Packages

### @wyxos/vibe
- Masonry layout component
- Import: `import { Masonry } from '@wyxos/vibe'`
- See `resources/js/components/TabContent.vue` for usage

### @wyxos/listing
- Listing table component
- Import: `import { Listing } from '@wyxos/listing'`

### @vueuse/core
- Vue composition utilities
- Import: `import { useLocalStorage } from '@vueuse/core'`

### reka-ui
- Headless UI components
- Used for accessible component primitives

---

## Testing Patterns

### Component Tests
```typescript
// ✅ DO: Test component behavior
import { mount } from '@vue/test-utils'
import { createRouter } from 'vue-router'

const router = createRouter({ history: createMemoryHistory() })
const wrapper = mount(Component, { global: { plugins: [router] } })
```

### Composable Tests
```typescript
// ✅ DO: Test composable logic
import { useFeature } from '@/composables/useFeature'

it('should do something', () => {
  const { state, method } = useFeature()
  expect(state.value).toBe(expected)
})
```

### Example Tests
- `resources/js/components/AppHeader.test.ts` - Component test
- `resources/js/composables/useBrowseService.test.ts` - Composable test
- `resources/js/pages/BrowseCore.test.ts` - Page integration test

