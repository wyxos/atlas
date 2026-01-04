# UI (shadcn-vue + Tailwind v4) - Design System Guidelines

This document outlines design principles and implementation guidelines for Atlas UI work.

**Stack**: Vue 3 + TypeScript + Tailwind CSS v4 + shadcn-vue (Reka UI primitives) + lucide-vue-next icons.

**Goal**: keep UI consistent, accessible, and easy to maintain.

---

## Core Design Principles

### 1. Typography System: 4 Sizes, 2 Weights

**Preferred sizes (pick from these first):**
- Size 1: `text-xl` — large headings
- Size 2: `text-lg` — subheadings / key labels
- Size 3: `text-sm` — body text / most UI copy
- Size 4: `text-xs` — helper text / small labels

**Preferred weights:**
- `font-semibold` — headings and emphasis
- `font-normal` — body text and general UI

Notes:
- Avoid introducing new font sizes/weights unless you are matching an existing component API.
- Prefer the existing primitives in this folder (e.g. `Heading.vue`) but keep usage constrained to the 4-size system above.

### 2. 8pt Grid System

All spacing values must be divisible by 8 or 4.

- ✅ DO: `p-2`, `p-4`, `p-6`, `gap-2`, `gap-4`, `gap-6`, `mt-4`
- ❌ DON’T: arbitrary spacing like `p-[13px]` unless there is a strong UI reason

Why:
- predictable rhythm
- easier to review
- fewer “one-off” layouts

### 3. 60/30/10 Color Rule

Use a simple distribution model:
- **60%**: neutral surfaces (app background, cards)
- **30%**: complementary/text (foreground, muted text, borders)
- **10%**: accent (primary actions, selected states)

In this project:
- Prefer semantic Tailwind tokens: `bg-background`, `text-foreground`, `border-border`, `bg-card`, etc.
- Use the named palette (e.g. `smart-blue-*`, `twilight-indigo-*`, `danger-*`) as accents/statuses.
- Avoid hard-coded hex colors in components; if a new token is truly needed, add it in `resources/css/app.css` under `@theme`.

### 4. Clean Visual Structure

- Group related controls in a single container (`Card`/panel) with consistent gaps
- Align labels/inputs/actions to a clear grid
- Prefer clarity over “clever” layouts

---

## Foundation

### Tailwind v4 Integration

- Styling is Tailwind v4 (CSS-first).
- Theme tokens live in `resources/css/app.css` using `@theme` / `@theme inline`.
- Prefer Tailwind utilities over custom CSS; add CSS only when utilities can’t express the rule.

### CSS Variables + Theme Tokens

- This repo already uses Tailwind v4 theme variables and a named palette in `resources/css/app.css`.
- Rule of thumb:
  - Component styling → Tailwind classes using existing tokens
  - New brand/system tokens → add to `resources/css/app.css` (not inline in components)

---

## Typography System

### Implementation Rules

- Use the 4-size system consistently across pages.
- Buttons/inputs should generally remain `text-sm`.
- Keep forms readable: labels `text-sm`, help text `text-xs`.

### Common Mistakes

- Using many size steps (`text-base`, `text-lg`, `text-xl`, `text-2xl`, …) within one view
- Mixing multiple weights (`font-medium`, `font-bold`, etc.) for minor emphasis

---

## 8pt Grid System

### Practical Examples

- Instead of `gap-5` → use `gap-4` or `gap-6`
- Instead of `px-5` → use `px-4` or `px-6`

### Review Rule

If spacing looks “odd”, first fix it by normalizing to the 4/8 grid before introducing new layout primitives.

---

## Component Architecture

### shadcn-vue Component Structure

- Components in this folder follow shadcn-vue patterns:
  - Reka UI primitives for behavior/accessibility
  - Tailwind utilities for styling
  - `data-slot` attributes for targeting internal parts
  - Variants often powered by `class-variance-authority` (`cva`)

### Implementation Rules

- Reuse existing components in `resources/js/components/ui/` before creating new ones.
- If you need a new primitive component:
  - prefer adding it via the shadcn-vue workflow (this repo has `components.json` configured)
  - keep the API small (variants only when there are real use-cases)
  - follow the existing conventions: `cn()` helper, `data-slot`, and token-based classes

### Oruga

- Oruga is used in parts of the app; overrides live in `resources/css/oruga/*`.
- Don’t restyle Oruga ad-hoc in random components—prefer centralized overrides.

---

## Visual Hierarchy

- Primary action per surface when possible.
- Use accent colors sparingly: selected state, CTA, or active filter.
- Keep secondary actions visually secondary (outline/ghost).

---

## Accessibility & UX

- Ensure contrast between text and background.
- Maintain keyboard navigation (especially dialogs, dropdowns, popovers).
- Prefer using the existing shadcn-vue primitives (they come with good a11y defaults).
- Add `data-test` attributes for UI elements that Playwright interacts with.

---

## Code Review Checklist

### Core Design Principles
- [ ] Typography uses the 4-size system and mostly 2 weights (`normal`, `semibold`)
- [ ] Spacing aligns to the 4/8 grid
- [ ] Colors follow 60/30/10 and avoid new hex values
- [ ] Layout is grouped/aligned and not “randomly spaced”

### Technical Implementation
- [ ] Uses existing UI components/variants where possible
- [ ] Uses theme tokens/classes instead of bespoke CSS
- [ ] Preserves a11y behavior for overlays/menus
- [ ] Adds `data-test` where needed for browser tests

### Common Issues to Flag
- [ ] One-off spacing/typography values
- [ ] Excessive accent color usage
- [ ] Inline hex colors or custom shadows
- [ ] New component created when an existing one fits
