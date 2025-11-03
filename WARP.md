# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

Project overview
- Stack: Laravel 12 + Inertia (Laravel + Vue 3) + Vite 7 + Tailwind CSS v4 + TypeScript
- SSR enabled: resources/js/ssr.ts is configured in vite.config.ts
- Code style: Laravel Pint
- Tests: Pest v4 (Feature, Unit, Browser via pest-plugin-browser + Playwright)
- Default DB: sqlite (see .env.example); MariaDB via Herd is available if configured

Architecture map (big picture)
- Routing
  - routes/web.php is the primary entry, rendering Inertia pages like Welcome and Dashboard.
  - routes/settings.php contains auth-protected settings routes (profile, password, appearance).
  - routes/auth.php contains auth scaffolding (login, register, email verification, password reset).
  - routes/console.php defines CLI commands.
  - Follow: always create the route when adding new functionality or endpoints.
- HTTP layer
  - Controllers in app/Http/Controllers (e.g., DashboardController, Settings/* controllers).
  - Validation via Form Requests in app/Http/Requests.
  - Inertia middleware app/Http/Middleware/HandleInertiaRequests shares global props (app name, auth user, quote, sidebar state) and sets root view resources/views/app.blade.php.
- Frontend (Inertia + Vue 3 + TS)
  - Pages under resources/js/pages (e.g., Welcome, Dashboard, settings/*) with layouts and shared UI components in resources/js/components and resources/js/layouts.
  - TypeScript-first setup; Tailwind v4; Wayfinder plugin generates typed helpers; Ziggy is available for named routes in JS if needed.
  - Icons: lucide-vue-next; do not add custom inline SVGs. Prefer lucide icons and use the size prop (not Tailwind classes) to size icons.
- Asset pipeline
  - Vite config (vite.config.ts) uses laravel-vite-plugin, @tailwindcss/vite, @vitejs/plugin-vue, and @laravel/vite-plugin-wayfinder (typed forms/routes). SSR input is configured.
- Testing
  - Pest v4 with phpunit.xml. Feature and Unit tests live in tests/Feature and tests/Unit. Browser tests (Pest Browser) live under tests/Feature/Browser. Playwright is present in package.json.

Commands you’ll commonly use
Setup
- PHP deps
  ```bash path=null start=null
  composer install
  ```
  - On Windows, the user has aliases: ci (install), cu (update), cii/cui/cri for ignore-req variants.
- Node deps
  ```bash path=null start=null
  npm install
  ```
- Database (sqlite default)
  ```bash path=null start=null
  php artisan migrate
  ```

Linting and formatting
- PHP (Pint)
  ```bash path=null start=null
  vendor/bin/pint --dirty
  ```
- JS/TS (ESLint) and formatting (Prettier)
  ```bash path=null start=null
  npm run lint --fix
  npm run format:check
  # or to auto-format
  npm run format
  ```

Tests (Pest via php artisan test)
- Full suite
  ```bash path=null start=null
  php artisan test --compact
  ```
- Single file
  ```bash path=null start=null
  php artisan test tests/Feature/DashboardTest.php
  ```
- Filter by test name
  ```bash path=null start=null
  php artisan test --filter=RegistrationTest
  ```
Notes
- Do not add --no-interaction to php artisan test (obsolete flag); just run php artisan test.
- Composer script exists as composer test (runs config:clear then php artisan test) if you prefer: composer run test

Local serving
- Served by Laravel Herd at a .test domain; do not start an HTTP server for normal use. Use the absolute URL helper when sharing links.
- If you need SSR during development, you can build with npm run build:ssr and start the SSR server via PHP (see composer scripts for dev:ssr). Prefer Herd for HTTP serving.

Repository conventions and preferences
- Scaffold with php artisan make:... (controllers, models, requests, etc.).
- Prefer defined types over PHPDoc; keep comments minimal and only when necessary.
- Frontend HTTP: use axios, not fetch.
- Icons: do not add custom SVGs; use lucide-vue-next and the size prop to size icons.
- Enums: use kebab-case for enum values (e.g., "credit-card").
- Variable naming: avoid one-letter or vague names.
- After backend work, verify UI alignment; after UI work, verify backend alignment.
- Conventional commits: one line, concise.
- When adding functionality or endpoints, create the route.

Notes for agents using Boost/Cursor/Copilot rules in this repo
- Search docs before framework-level changes: use version-specific Laravel/Inertia/Tailwind/Pest guidance.
- Use Artisan command discovery; php artisan make:... and php artisan list help confirm parameters.
- Use Tinker for quick runtime checks; prefer tests over ad-hoc scripts when functionality is covered.
- Pint must be run before finalizing code changes (vendor/bin/pint --dirty).
- Tests should be written with Pest (php artisan make:test --pest). Run a minimal set (file or filter) while iterating.
- Herd serves the app; don’t run HTTP-serve commands to make it available.

Environment
- Default DB is MariaDB for local dev/testing.
- Deployed app path (server): /home/wyxos/webapps/atlas

Realtime / Broadcasting (Echo + Reverb)
- Use @laravel/echo-vue hooks. Do NOT manually instantiate window.Echo in app code.
- Place hooks at setup scope (e.g., <script setup>) so they mount automatically. Do not wrap them in onMounted.
- Channel type must match your event's broadcastOn return type:
  - Channel => useEchoPublic('channel', event, cb)
  - PrivateChannel => useEcho(`channel`, event, cb)
  - PresenceChannel => useEchoPresence('channel', event, cb)
- If you customize the event name via broadcastAs('x.y'), listen with a leading dot: '.x.y' (per Laravel docs).
- Reverb + Echo config is centralized via configureEcho in resources/js/app.ts and VITE_REVERB_* envs; do not hardcode host/port.

Examples
- Public channel (Reverb):
  ```ts path=null start=null
  import { useEchoPublic } from '@laravel/echo-vue';

  // Listen on public channel "demo" for an event broadcastAs('demo.ping')
  useEchoPublic('demo', '.demo.ping', (e: { message: string }) => {
    console.log(e.message);
  });
  ```
- Private channel:
  ```ts path=null start=null
  import { useEcho } from '@laravel/echo-vue';

  useEcho(`orders.${orderId}`, 'OrderShipmentStatusUpdated', (e) => {
    console.log(e.order);
  });
  ```
- Server-side broadcast event (public):
  ```php path=null start=null
  <?php
  namespace App\Events;

  use Illuminate\Broadcasting\Channel;
  use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
  use Illuminate\Foundation\Events\Dispatchable;
  use Illuminate\Queue\SerializesModels;

  class DemoPing implements ShouldBroadcast
  {
      use Dispatchable, SerializesModels;

      public function __construct(public string $message) {}

      public function broadcastOn(): Channel { return new Channel('demo'); }

      public function broadcastAs(): string { return 'demo.ping'; }
  }
  ```

Common pitfalls
- 403 POST /broadcasting/auth: You used private/presence without an authenticated user or without a channel auth callback in routes/channels.php; or you meant a public channel but used useEcho instead of useEchoPublic.
- No event received: Check channel name matches broadcastOn. If broadcastAs is used, remember the leading '.' in the listener. Ensure Reverb envs are set (REVERB_* and matching VITE_REVERB_*).
- Don’t create ad-hoc echo.ts or window.Pusher hacks. Use configureEcho in resources/js/app.ts and the hooks above.

As a responsible developer, you'll lint after each changes and run tests suite corresponding to the changes made.

Vibe masonry engine alignment
- The grid/infinite scroll is powered by `@wyxos/vibe` (local workspace path: `..\\..\\vue\\vibe`). When behavior or UX depends on Masonry (e.g., refresh-on-empty vs load-next), prefer adjusting Atlas components via the Masonry public API (`remove`, `removeMany`, `removeAll`, `refreshCurrentPage`, `loadNext`). If changes require updates to the plugin, open the Vibe repo and align docs/examples accordingly.
