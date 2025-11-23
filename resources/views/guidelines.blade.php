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
    <body class="min-h-screen bg-gradient-to-br from-smart-blue-50 to-smart-blue-100">
        <div id="app"></div>
        <div class="container mx-auto px-4 py-8 max-w-6xl">
            <div class="bg-white rounded-lg shadow-xl p-8 md:p-12">
                <div class="mb-8">
                    <h1 class="text-4xl md:text-5xl font-bold text-prussian-blue mb-4">
                        UI/UX Guidelines
                    </h1>
                    <p class="text-lg text-regal-navy-600">
                        Design system and component showcase for {{ config('app.name', 'Atlas') }}
                    </p>
                </div>

                <!-- Color Palette -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-prussian-blue mb-6 pb-2 border-b-2 border-smart-blue-200">
                        Color Palette
                    </h2>
                    <div class="space-y-8">
                        <!-- Smart Blue -->
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Smart Blue</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #011428;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #022950;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #023d78;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0352a0;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 border-prussian-blue" style="background-color: #0466c8;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0f85fa;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #4ba3fb;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #87c2fd;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #c3e0fe;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Sapphire -->
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Sapphire</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #011121;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #012242;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #023263;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #034384;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 border-prussian-blue" style="background-color: #0353a4;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0576e8;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #3698fb;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #79bbfc;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #bcddfe;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Regal Navy -->
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Regal Navy</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000c19;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #011932;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #01254b;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #023164;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 border-prussian-blue" style="background-color: #023e7d;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0363c9;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #1d89fc;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #68b0fd;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #b4d8fe;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Prussian Blue -->
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Prussian Blue</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #00040a;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000714;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000b1f;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000e29;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2 border-smart-blue-500" style="background-color: #001233;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600 font-semibold">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #00328f;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0052eb;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #4788ff;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #a3c3ff;"></div>
                                    <span class="text-xs mt-1 text-slate-grey-600">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Neutral Colors -->
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Neutral Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Twilight Indigo</h4>
                                    <div class="h-12 rounded" style="background-color: #33415c;"></div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Blue Slate</h4>
                                    <div class="h-12 rounded" style="background-color: #5c677d;"></div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Slate Grey</h4>
                                    <div class="h-12 rounded" style="background-color: #7d8597;"></div>
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-slate-grey-700 mb-2">Lavender Grey</h4>
                                    <div class="h-12 rounded" style="background-color: #979dac;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Typography -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-prussian-blue mb-6 pb-2 border-b-2 border-smart-blue-200">
                        Typography
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h1 class="text-5xl font-bold text-prussian-blue mb-2">Heading 1</h1>
                            <code class="text-sm text-slate-grey-600">text-5xl font-bold text-prussian-blue</code>
                        </div>
                        <div>
                            <h2 class="text-4xl font-bold text-prussian-blue mb-2">Heading 2</h2>
                            <code class="text-sm text-slate-grey-600">text-4xl font-bold text-prussian-blue</code>
                        </div>
                        <div>
                            <h3 class="text-3xl font-semibold text-regal-navy mb-2">Heading 3</h3>
                            <code class="text-sm text-slate-grey-600">text-3xl font-semibold text-regal-navy</code>
                        </div>
                        <div>
                            <h4 class="text-2xl font-semibold text-regal-navy mb-2">Heading 4</h4>
                            <code class="text-sm text-slate-grey-600">text-2xl font-semibold text-regal-navy</code>
                        </div>
                        <div>
                            <h5 class="text-xl font-medium text-sapphire mb-2">Heading 5</h5>
                            <code class="text-sm text-slate-grey-600">text-xl font-medium text-sapphire</code>
                        </div>
                        <div>
                            <h6 class="text-lg font-medium text-sapphire mb-2">Heading 6</h6>
                            <code class="text-sm text-slate-grey-600">text-lg font-medium text-sapphire</code>
                        </div>
                        <div>
                            <p class="text-base text-slate-grey-700 mb-2">Body text - Regular paragraph text that is easy to read and provides good contrast.</p>
                            <code class="text-sm text-slate-grey-600">text-base text-slate-grey-700</code>
                        </div>
                        <div>
                            <p class="text-sm text-slate-grey-600 mb-2">Small text - Used for captions, hints, and secondary information.</p>
                            <code class="text-sm text-slate-grey-600">text-sm text-slate-grey-600</code>
                        </div>
                    </div>
                </section>

                <!-- Buttons -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-prussian-blue mb-6 pb-2 border-b-2 border-smart-blue-200">
                        Buttons
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Primary Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg" style="background-color: #0466c8;" onmouseover="this.style.backgroundColor='#0352a0'" onmouseout="this.style.backgroundColor='#0466c8'">
                                    Smart Blue Primary
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg" style="background-color: #0353a4;" onmouseover="this.style.backgroundColor='#034384'" onmouseout="this.style.backgroundColor='#0353a4'">
                                    Sapphire Primary
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg disabled:opacity-50 disabled:cursor-not-allowed" style="background-color: #0466c8;" disabled>
                                    Disabled
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Outline Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 border-2 font-semibold rounded-lg transition-colors" style="border-color: #0466c8; color: #0466c8;" onmouseover="this.style.backgroundColor='#c3e0fe'" onmouseout="this.style.backgroundColor='transparent'">
                                    Outline Smart Blue
                                </button>
                                <button class="px-6 py-3 border-2 font-semibold rounded-lg transition-colors" style="border-color: #0353a4; color: #0353a4;" onmouseover="this.style.backgroundColor='#bcddfe'" onmouseout="this.style.backgroundColor='transparent'">
                                    Outline Sapphire
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Ghost Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 font-semibold rounded-lg transition-colors" style="color: #0466c8;" onmouseover="this.style.backgroundColor='#c3e0fe'" onmouseout="this.style.backgroundColor='transparent'">
                                    Ghost Smart Blue
                                </button>
                                <button class="px-6 py-3 font-semibold rounded-lg transition-colors" style="color: #0353a4;" onmouseover="this.style.backgroundColor='#bcddfe'" onmouseout="this.style.backgroundColor='transparent'">
                                    Ghost Sapphire
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-xl font-semibold text-prussian-blue mb-4">Button Sizes</h3>
                            <div class="flex flex-wrap items-center gap-4">
                                <button class="px-3 py-1.5 text-sm text-white font-semibold rounded-lg transition-colors" style="background-color: #0466c8;">
                                    Small
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors" style="background-color: #0466c8;">
                                    Medium
                                </button>
                                <button class="px-8 py-4 text-lg text-white font-semibold rounded-lg transition-colors" style="background-color: #0466c8;">
                                    Large
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Form Inputs -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-prussian-blue mb-6 pb-2 border-b-2 border-smart-blue-200">
                        Form Inputs
                    </h2>
                    <div class="space-y-6 max-w-2xl">
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #001233;">
                                Text Input
                            </label>
                            <input
                                type="text"
                                placeholder="Enter text here"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="border: 2px solid #87c2fd;"
                                onfocus="this.style.borderColor='#0466c8'; this.style.boxShadow='0 0 0 3px rgba(4, 102, 200, 0.1)'"
                                onblur="this.style.borderColor='#87c2fd'; this.style.boxShadow='none'"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #001233;">
                                Email Input
                            </label>
                            <input
                                type="email"
                                placeholder="you@example.com"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="border: 2px solid #87c2fd;"
                                onfocus="this.style.borderColor='#0466c8'; this.style.boxShadow='0 0 0 3px rgba(4, 102, 200, 0.1)'"
                                onblur="this.style.borderColor='#87c2fd'; this.style.boxShadow='none'"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #001233;">
                                Password Input
                            </label>
                            <input
                                type="password"
                                placeholder="••••••••"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="border: 2px solid #87c2fd;"
                                onfocus="this.style.borderColor='#0466c8'; this.style.boxShadow='0 0 0 3px rgba(4, 102, 200, 0.1)'"
                                onblur="this.style.borderColor='#87c2fd'; this.style.boxShadow='none'"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #001233;">
                                Textarea
                            </label>
                            <textarea
                                rows="4"
                                placeholder="Enter your message here..."
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors resize-none"
                                style="border: 2px solid #87c2fd;"
                                onfocus="this.style.borderColor='#0466c8'; this.style.boxShadow='0 0 0 3px rgba(4, 102, 200, 0.1)'"
                                onblur="this.style.borderColor='#87c2fd'; this.style.boxShadow='none'"
                            ></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #001233;">
                                Select Dropdown
                            </label>
                            <select class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors" style="border: 2px solid #87c2fd;">
                                <option>Option 1</option>
                                <option>Option 2</option>
                                <option>Option 3</option>
                            </select>
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="w-4 h-4 rounded focus:ring-2"
                                    style="accent-color: #0466c8;"
                                >
                                <span class="text-sm" style="color: #5c677d;">Checkbox option</span>
                            </label>
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name="radio-example"
                                    class="w-4 h-4 focus:ring-2"
                                    style="accent-color: #0466c8;"
                                >
                                <span class="text-sm" style="color: #5c677d;">Radio option 1</span>
                            </label>
                            <label class="flex items-center gap-2 mt-2">
                                <input
                                    type="radio"
                                    name="radio-example"
                                    class="w-4 h-4 focus:ring-2"
                                    style="accent-color: #0466c8;"
                                >
                                <span class="text-sm" style="color: #5c677d;">Radio option 2</span>
                            </label>
                        </div>
                    </div>
                </section>

                <!-- Tables -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-prussian-blue mb-6 pb-2 border-b-2 border-smart-blue-200">
                        Tables
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr style="background-color: #c3e0fe; border-bottom: 2px solid #87c2fd;">
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #001233;">Name</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #001233;">Email</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #001233;">Role</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #001233;">Status</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #001233;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="transition-colors" style="border-bottom: 1px solid #c3e0fe;" onmouseover="this.style.backgroundColor='#c3e0fe'" onmouseout="this.style.backgroundColor='transparent'">
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">John Doe</td>
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">john@example.com</td>
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">Admin</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium" style="background-color: #d0d7e5; color: #33415c;">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors" style="color: #0466c8;" onmouseover="this.style.color='#023e7d'" onmouseout="this.style.color='#0466c8'">Edit</button>
                                    </td>
                                </tr>
                                <tr class="transition-colors" style="border-bottom: 1px solid #c3e0fe;" onmouseover="this.style.backgroundColor='#c3e0fe'" onmouseout="this.style.backgroundColor='transparent'">
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">Jane Smith</td>
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">jane@example.com</td>
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium" style="background-color: #d0d7e5; color: #33415c;">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors" style="color: #0466c8;" onmouseover="this.style.color='#023e7d'" onmouseout="this.style.color='#0466c8'">Edit</button>
                                    </td>
                                </tr>
                                <tr class="transition-colors" style="border-bottom: 1px solid #c3e0fe;" onmouseover="this.style.backgroundColor='#c3e0fe'" onmouseout="this.style.backgroundColor='transparent'">
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">Bob Johnson</td>
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">bob@example.com</td>
                                    <td class="px-6 py-4 text-sm" style="color: #5c677d;">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium" style="background-color: #e5e7ea; color: #7d8597;">
                                            Inactive
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors" style="color: #0466c8;" onmouseover="this.style.color='#023e7d'" onmouseout="this.style.color='#0466c8'">Edit</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Links -->
                <section class="mb-12">
                    <h2 class="text-3xl font-bold text-prussian-blue mb-6 pb-2 border-b-2 border-smart-blue-200">
                        Links
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <a href="#" class="underline font-medium transition-colors" style="color: #0466c8;" onmouseover="this.style.color='#023e7d'" onmouseout="this.style.color='#0466c8'">
                                Default Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors" style="color: #0466c8;" onmouseover="this.style.color='#023e7d'" onmouseout="this.style.color='#0466c8'">
                                Link without underline
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors" style="color: #0353a4;" onmouseover="this.style.color='#023e7d'" onmouseout="this.style.color='#0353a4'">
                                Sapphire Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors" style="color: #7d8597;" onmouseover="this.style.color='#5c677d'" onmouseout="this.style.color='#7d8597'">
                                Muted Link
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Navigation -->
                <div class="mt-8 pt-8" style="border-top: 2px solid #87c2fd;">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 font-medium transition-colors" style="color: #0466c8;" onmouseover="this.style.color='#023e7d'" onmouseout="this.style.color='#0466c8'">
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
