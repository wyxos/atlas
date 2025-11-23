<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>UI/UX Guidelines - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="min-h-screen bg-gradient-to-br from-blue-50 to-blue-100">
        <div id="app"></div>
        <div class="container mx-auto px-4 py-8 max-w-6xl">
            <div class="bg-white rounded-lg shadow-xl p-8 md:p-12">
                <div class="mb-8">
                    <h1 class="text-4xl md:text-5xl font-bold text-blue-900 mb-4">
                        UI/UX Guidelines
                    </h1>
                    <p class="text-lg text-blue-700">
                        Design system and component showcase for {{ config('app.name', 'Atlas') }}
                    </p>
                </div>

                <!-- Typography -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-blue-900 mb-6 pb-2 border-b-2 border-blue-200">
                        Typography
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h1 class="text-5xl font-bold text-blue-900 mb-2">Heading 1</h1>
                            <code class="text-sm text-gray-600">text-5xl font-bold</code>
                        </div>
                        <div>
                            <h2 class="text-4xl font-bold text-blue-900 mb-2">Heading 2</h2>
                            <code class="text-sm text-gray-600">text-4xl font-bold</code>
                        </div>
                        <div>
                            <h3 class="text-3xl font-semibold text-blue-900 mb-2">Heading 3</h3>
                            <code class="text-sm text-gray-600">text-3xl font-semibold</code>
                        </div>
                        <div>
                            <h4 class="text-2xl font-semibold text-blue-900 mb-2">Heading 4</h4>
                            <code class="text-sm text-gray-600">text-2xl font-semibold</code>
                        </div>
                        <div>
                            <h5 class="text-xl font-medium text-blue-900 mb-2">Heading 5</h5>
                            <code class="text-sm text-gray-600">text-xl font-medium</code>
                        </div>
                        <div>
                            <h6 class="text-lg font-medium text-blue-900 mb-2">Heading 6</h6>
                            <code class="text-sm text-gray-600">text-lg font-medium</code>
                        </div>
                        <div>
                            <p class="text-base text-gray-700 mb-2">Body text - Regular paragraph text that is easy to read and provides good contrast.</p>
                            <code class="text-sm text-gray-600">text-base</code>
                        </div>
                        <div>
                            <p class="text-sm text-gray-600 mb-2">Small text - Used for captions, hints, and secondary information.</p>
                            <code class="text-sm text-gray-600">text-sm</code>
                        </div>
                    </div>
                </section>

                <!-- Buttons -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-blue-900 mb-6 pb-2 border-b-2 border-blue-200">
                        Buttons
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-xl font-semibold text-blue-900 mb-4">Primary Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors shadow-lg">
                                    Primary Button
                                </button>
                                <button class="px-6 py-3 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg transition-colors shadow-lg">
                                    Secondary Button
                                </button>
                                <button class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors shadow-lg disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                    Disabled
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-blue-900 mb-4">Outline Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 border-2 border-blue-600 text-blue-600 hover:bg-blue-50 font-semibold rounded-lg transition-colors">
                                    Outline Primary
                                </button>
                                <button class="px-6 py-3 border-2 border-amber-500 text-amber-600 hover:bg-amber-50 font-semibold rounded-lg transition-colors">
                                    Outline Secondary
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-blue-900 mb-4">Ghost Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 text-blue-600 hover:bg-blue-50 font-semibold rounded-lg transition-colors">
                                    Ghost Button
                                </button>
                                <button class="px-6 py-3 text-amber-600 hover:bg-amber-50 font-semibold rounded-lg transition-colors">
                                    Ghost Secondary
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-blue-900 mb-4">Button Sizes</h3>
                            <div class="flex flex-wrap items-center gap-4">
                                <button class="px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                                    Small
                                </button>
                                <button class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                                    Medium
                                </button>
                                <button class="px-8 py-4 text-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition-colors">
                                    Large
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Form Inputs -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-blue-900 mb-6 pb-2 border-b-2 border-blue-200">
                        Form Inputs
                    </h2>
                    <div class="space-y-6 max-w-2xl">
                        <div>
                            <label class="block text-sm font-medium text-blue-900 mb-2">
                                Text Input
                            </label>
                            <input
                                type="text"
                                placeholder="Enter text here"
                                class="w-full px-4 py-3 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition-colors"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-blue-900 mb-2">
                                Email Input
                            </label>
                            <input
                                type="email"
                                placeholder="you@example.com"
                                class="w-full px-4 py-3 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition-colors"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-blue-900 mb-2">
                                Password Input
                            </label>
                            <input
                                type="password"
                                placeholder="••••••••"
                                class="w-full px-4 py-3 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition-colors"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-blue-900 mb-2">
                                Textarea
                            </label>
                            <textarea
                                rows="4"
                                placeholder="Enter your message here..."
                                class="w-full px-4 py-3 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition-colors resize-none"
                            ></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-blue-900 mb-2">
                                Select Dropdown
                            </label>
                            <select class="w-full px-4 py-3 border-2 border-blue-200 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition-colors">
                                <option>Option 1</option>
                                <option>Option 2</option>
                                <option>Option 3</option>
                            </select>
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="w-4 h-4 text-blue-600 border-blue-300 rounded focus:ring-blue-500"
                                >
                                <span class="text-sm text-gray-700">Checkbox option</span>
                            </label>
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="radio-example"
                                    class="w-4 h-4 text-blue-600 border-blue-300 focus:ring-blue-500"
                                >
                                <span class="text-sm text-gray-700">Radio option 1</span>
                            </label>
                            <label class="flex items-center gap-2 mt-2">
                                <input
                                    type="radio"
                                    name="radio-example"
                                    class="w-4 h-4 text-blue-600 border-blue-300 focus:ring-blue-500"
                                >
                                <span class="text-sm text-gray-700">Radio option 2</span>
                            </label>
                        </div>
                    </div>
                </section>

                <!-- Tables -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-blue-900 mb-6 pb-2 border-b-2 border-blue-200">
                        Tables
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-blue-50 border-b-2 border-blue-200">
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-blue-900">Name</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-blue-900">Email</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-blue-900">Role</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-blue-900">Status</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-blue-900">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-blue-100 hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 text-sm text-gray-700">John Doe</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">john@example.com</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">Admin</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                    </td>
                                </tr>
                                <tr class="border-b border-blue-100 hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 text-sm text-gray-700">Jane Smith</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">jane@example.com</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-medium">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                    </td>
                                </tr>
                                <tr class="border-b border-blue-100 hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 text-sm text-gray-700">Bob Johnson</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">bob@example.com</td>
                                    <td class="px-6 py-4 text-sm text-gray-700">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-xs font-medium">
                                            Inactive
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Links -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-blue-900 mb-6 pb-2 border-b-2 border-blue-200">
                        Links
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">
                                Default Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="text-blue-600 hover:text-blue-800 font-medium">
                                Link without underline
                            </a>
                        </div>
                        <div>
                            <a href="#" class="text-amber-600 hover:text-amber-800 font-medium">
                                Secondary Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="text-gray-600 hover:text-gray-800 font-medium">
                                Muted Link
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Navigation -->
                <div class="mt-8 pt-8 border-t-2 border-blue-200">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-800 font-medium">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Home
                    </a>
                </div>
            </div>
        </div>
    </body>
</html>

