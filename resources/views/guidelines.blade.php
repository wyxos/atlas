<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>UI/UX Guidelines - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="bg-prussian-blue-500 text-twilight-indigo-900 min-h-screen">
        <div id="app"></div>
        <main class="container mx-auto px-4 py-8 max-w-6xl">
            <div class="bg-prussian-blue-600 rounded-lg shadow-2xl p-8 lg:p-12">
                <div class="mb-8">
                    <h1 class="text-5xl font-bold text-smart-blue-900 mb-4">
                        UI/UX Guidelines
                    </h1>
                    <p class="text-lg text-blue-slate-700">
                        Design system and component showcase for {{ config('app.name', 'Atlas') }}
                    </p>
                </div>

                <!-- Color Palette -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-smart-blue-900 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
                        Color Palette
                    </h2>
                    <div class="space-y-8">
                        <!-- Smart Blue -->
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Smart Blue</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-100"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-200"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-300"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-400"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 bg-smart-blue-500 border-smart-blue-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-600"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-800"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-smart-blue-900"></div>
                                    <span class="text-xs mt-1 text-prussian-blue-500">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Sapphire -->
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Sapphire</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-100"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-200"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-300"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-400"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 bg-sapphire-500 border-sapphire-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-600"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-800"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-sapphire-900"></div>
                                    <span class="text-xs mt-1 text-prussian-blue-500">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Regal Navy -->
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Regal Navy</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-100"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-200"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-300"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-400"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 bg-regal-navy-500 border-regal-navy-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-600"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-800"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-regal-navy-900"></div>
                                    <span class="text-xs mt-1 text-prussian-blue-500">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Prussian Blue -->
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Prussian Blue</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-100"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-200"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-300"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-400"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 bg-prussian-blue-500 border-prussian-blue-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-600"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-800"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-prussian-blue-900"></div>
                                    <span class="text-xs mt-1 text-prussian-blue-500">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Danger/Warning -->
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Danger/Warning</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-100"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-200"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-300"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-400"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 bg-danger-500 border-danger-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-600"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-800"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-danger-900"></div>
                                    <span class="text-xs mt-1 text-prussian-blue-500">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Success -->
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Success</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-100"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-200"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-300"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-400"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 bg-success-500 border-success-300"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-600"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-700"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-800"></div>
                                    <span class="text-xs mt-1 text-twilight-indigo-700">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded bg-success-900"></div>
                                    <span class="text-xs mt-1 text-prussian-blue-500">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Neutral Colors -->
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Neutral Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Twilight Indigo</h4>
                                    <div class="h-12 rounded bg-twilight-indigo-500"></div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Blue Slate</h4>
                                    <div class="h-12 rounded bg-blue-slate-500"></div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Slate Grey</h4>
                                    <div class="h-12 rounded bg-slate-grey-500"></div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Lavender Grey</h4>
                                    <div class="h-12 rounded bg-lavender-grey-500"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Typography -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-smart-blue-900 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
                        Typography
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h1 class="text-5xl font-bold text-smart-blue-900 mb-2">Heading 1</h1>
                            <code class="text-sm text-twilight-indigo-700">text-5xl font-bold text-smart-blue-900</code>
                        </div>
                        <div>
                            <h2 class="text-4xl font-bold text-smart-blue-900 mb-2">Heading 2</h2>
                            <code class="text-sm text-twilight-indigo-700">text-4xl font-bold text-smart-blue-900</code>
                        </div>
                        <div>
                            <h3 class="text-3xl font-semibold text-regal-navy-900 mb-2">Heading 3</h3>
                            <code class="text-sm text-twilight-indigo-700">text-3xl font-semibold text-regal-navy-900</code>
                        </div>
                        <div>
                            <h4 class="text-2xl font-semibold text-regal-navy-900 mb-2">Heading 4</h4>
                            <code class="text-sm text-twilight-indigo-700">text-2xl font-semibold text-regal-navy-900</code>
                        </div>
                        <div>
                            <h5 class="text-xl font-medium text-sapphire-900 mb-2">Heading 5</h5>
                            <code class="text-sm text-twilight-indigo-700">text-xl font-medium text-sapphire-900</code>
                        </div>
                        <div>
                            <h6 class="text-lg font-medium text-sapphire-900 mb-2">Heading 6</h6>
                            <code class="text-sm text-twilight-indigo-700">text-lg font-medium text-sapphire-900</code>
                        </div>
                        <div>
                            <p class="text-base text-twilight-indigo-900 mb-2">Body text - Regular paragraph text that is easy to read and provides good contrast on dark backgrounds.</p>
                            <code class="text-sm text-twilight-indigo-700">text-base text-twilight-indigo-900</code>
                        </div>
                        <div>
                            <p class="text-sm text-slate-grey-700 mb-2">Small text - Used for captions, hints, and secondary information.</p>
                            <code class="text-sm text-twilight-indigo-700">text-sm text-slate-grey-700</code>
                        </div>
                    </div>
                </section>

                <!-- Buttons -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-smart-blue-900 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
                        Buttons
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Primary Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg cursor-pointer bg-smart-blue-500 hover:bg-smart-blue-600">
                                    Smart Blue Primary
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg cursor-pointer bg-sapphire-500 hover:bg-sapphire-600">
                                    Sapphire Primary
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg opacity-50 cursor-not-allowed bg-smart-blue-500" disabled>
                                    Disabled
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Outline Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 border-2 font-semibold rounded-lg transition-colors cursor-pointer border-smart-blue-600 text-smart-blue-600 bg-transparent hover:bg-smart-blue-300">
                                    Outline Smart Blue
                                </button>
                                <button class="px-6 py-3 border-2 font-semibold rounded-lg transition-colors cursor-pointer border-sapphire-600 text-sapphire-600 bg-transparent hover:bg-sapphire-300">
                                    Outline Sapphire
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Ghost Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 font-semibold rounded-lg transition-colors cursor-pointer text-smart-blue-700 bg-transparent hover:bg-smart-blue-300">
                                    Ghost Smart Blue
                                </button>
                                <button class="px-6 py-3 font-semibold rounded-lg transition-colors cursor-pointer text-sapphire-700 bg-transparent hover:bg-sapphire-300">
                                    Ghost Sapphire
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Button Sizes</h3>
                            <div class="flex flex-wrap items-center gap-4">
                                <button class="px-3 py-1.5 text-sm text-white font-semibold rounded-lg transition-colors cursor-pointer bg-smart-blue-500">
                                    Small
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors cursor-pointer bg-smart-blue-500">
                                    Medium
                                </button>
                                <button class="px-8 py-4 text-lg text-white font-semibold rounded-lg transition-colors cursor-pointer bg-smart-blue-500">
                                    Large
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Danger Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg cursor-pointer bg-danger-600 hover:bg-danger-700">
                                    Danger Primary
                                </button>
                                <button class="px-6 py-3 border-2 font-semibold rounded-lg transition-colors cursor-pointer border-danger-600 text-danger-600 bg-transparent hover:bg-danger-300">
                                    Danger Outline
                                </button>
                                <button class="px-6 py-3 font-semibold rounded-lg transition-colors cursor-pointer text-danger-700 bg-transparent hover:bg-danger-300">
                                    Danger Ghost
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Icon Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="p-2 border-2 rounded-lg transition-all cursor-pointer border-danger-700 text-danger-700 bg-transparent hover:bg-danger-500 hover:border-danger-600 hover:text-danger-900">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                                <button class="p-2 border-2 rounded-lg transition-all cursor-pointer border-success-300 text-success-300 bg-transparent hover:bg-success-200 hover:border-success-300 hover:text-success-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                </button>
                            </div>
                            <code class="block mt-2 text-xs text-twilight-indigo-700">p-2 border-2 rounded-lg border-danger-700 text-danger-700 hover:bg-danger-500 hover:border-danger-600 hover:text-danger-900</code>
                        </div>
                    </div>
                </section>

                <!-- Badges & Tags -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-smart-blue-900 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
                        Badges & Tags
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <h3 class="text-xl font-semibold text-smart-blue-900 mb-4">Status Badges</h3>
                            <div class="flex flex-wrap gap-4">
                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-success-300 text-success-600 border border-success-400">
                                    Verified
                                </span>
                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-smart-blue-300 text-smart-blue-700 border border-smart-blue-500">
                                    Active
                                </span>
                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-twilight-indigo-500 text-twilight-indigo-700 border border-blue-slate-500">
                                    Inactive
                                </span>
                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-danger-300 text-danger-800 border border-danger-600">
                                    Error
                                </span>
                                <span class="px-3 py-1 rounded-full text-xs font-medium bg-sapphire-300 text-sapphire-800 border border-sapphire-500">
                                    Pending
                                </span>
                            </div>
                            <code class="block mt-2 text-xs text-twilight-indigo-700">Verified: bg-success-300 text-success-600 border-success-400. Active: bg-smart-blue-300 text-smart-blue-700 border-smart-blue-500</code>
                        </div>
                    </div>
                </section>

                <!-- Form Inputs -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-smart-blue-900 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
                        Form Inputs
                    </h2>
                    <div class="space-y-6 max-w-2xl">
                        <div>
                            <label class="block text-sm font-medium mb-2 text-smart-blue-900">
                                Text Input
                            </label>
                            <input
                                type="text"
                                placeholder="Enter text here"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-smart-blue-600/20"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2 text-smart-blue-900">
                                Email Input
                            </label>
                            <input
                                type="email"
                                placeholder="you@example.com"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-smart-blue-600/20"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2 text-smart-blue-900">
                                Password Input
                            </label>
                            <input
                                type="password"
                                placeholder="••••••••"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-smart-blue-600/20"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2 text-smart-blue-900">
                                Textarea
                            </label>
                            <textarea
                                rows="4"
                                placeholder="Enter your message here..."
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors resize-none bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-smart-blue-600/20"
                            ></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2 text-smart-blue-900">
                                Select Dropdown
                            </label>
                            <select class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-900 focus:border-smart-blue-600 focus:ring-smart-blue-600/20">
                                <option class="bg-prussian-blue-500 text-twilight-indigo-900">Option 1</option>
                                <option class="bg-prussian-blue-500 text-twilight-indigo-900">Option 2</option>
                                <option class="bg-prussian-blue-500 text-twilight-indigo-900">Option 3</option>
                            </select>
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="w-4 h-4 rounded focus:ring-2 accent-smart-blue-500"
                                >
                                <span class="text-sm text-twilight-indigo-900">Checkbox option</span>
                            </label>
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="radio-example"
                                    class="w-4 h-4 focus:ring-2 accent-smart-blue-500"
                                >
                                <span class="text-sm text-twilight-indigo-900">Radio option 1</span>
                            </label>
                            <label class="flex items-center gap-2 mt-2">
                                <input
                                    type="radio"
                                    name="radio-example"
                                    class="w-4 h-4 focus:ring-2 accent-smart-blue-500"
                                >
                                <span class="text-sm text-twilight-indigo-900">Radio option 2</span>
                            </label>
                        </div>
                    </div>
                </section>

                <!-- Tables -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-smart-blue-900 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
                        Tables
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-smart-blue-300 border-b-2 border-smart-blue-500">
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-900">Name</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-900">Email</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-900">Role</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-900">Status</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-900">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="transition-colors border-b border-twilight-indigo-500 hover:bg-smart-blue-300">
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">John Doe</td>
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">john@example.com</td>
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">Admin</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium bg-smart-blue-300 text-smart-blue-700 border border-smart-blue-500">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors cursor-pointer text-smart-blue-700 hover:text-smart-blue-600">Edit</button>
                                    </td>
                                </tr>
                                <tr class="transition-colors border-b border-twilight-indigo-500 hover:bg-smart-blue-300">
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">Jane Smith</td>
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">jane@example.com</td>
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium bg-smart-blue-300 text-smart-blue-700 border border-smart-blue-500">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors cursor-pointer text-smart-blue-700 hover:text-smart-blue-600">Edit</button>
                                    </td>
                                </tr>
                                <tr class="transition-colors border-b border-twilight-indigo-500 hover:bg-smart-blue-300">
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">Bob Johnson</td>
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">bob@example.com</td>
                                    <td class="px-6 py-4 text-sm text-twilight-indigo-900">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium bg-twilight-indigo-500 text-twilight-indigo-700 border border-blue-slate-500">
                                            Inactive
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors cursor-pointer text-smart-blue-700 hover:text-smart-blue-600">Edit</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Links -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-smart-blue-900 mb-6 pb-2 border-b-2 border-twilight-indigo-500">
                        Links
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <a href="#" class="underline font-medium transition-colors text-smart-blue-700 hover:text-smart-blue-600">
                                Default Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors text-smart-blue-700 hover:text-smart-blue-600">
                                Link without underline
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors text-sapphire-700 hover:text-sapphire-600">
                                Sapphire Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors text-twilight-indigo-700 hover:text-slate-grey-700">
                                Muted Link
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Navigation -->
                <div class="mt-8 pt-8 border-t-2 border-twilight-indigo-500">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 font-medium transition-colors text-smart-blue-700 hover:text-smart-blue-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Home
                    </a>
                </div>
            </div>
        </main>
    </body>
</html>
