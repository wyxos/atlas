<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>UI/UX Guidelines - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        @include('partials.favicons')
    </head>
    <body style="background-color: #001233; color: #d0d7e5; min-height: 100vh;">
        <div id="app"></div>
        <div class="container mx-auto px-4 py-8 max-w-6xl">
            <div style="background-color: #000e29; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3); padding: 2rem 3rem;">
                <div class="mb-8">
                    <h1 style="font-size: 2.5rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1rem;">
                        UI/UX Guidelines
                    </h1>
                    <p style="font-size: 1.125rem; color: #a0aecb;">
                        Design system and component showcase for {{ config('app.name', 'Atlas') }}
                    </p>
                </div>

                <!-- Color Palette -->
                <section class="mb-12">
                    <h2 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #33415c;">
                        Color Palette
                    </h2>
                    <div class="space-y-8">
                        <!-- Smart Blue -->
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Smart Blue</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #011428;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #022950;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #023d78;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0352a0;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2" style="background-color: #0466c8; border-color: #4ba3fb;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5; font-weight: 600;">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0f85fa;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #4ba3fb;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #87c2fd;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #c3e0fe;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #001233;">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Sapphire -->
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Sapphire</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #011121;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #012242;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #023263;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #034384;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2" style="background-color: #0353a4; border-color: #3698fb;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5; font-weight: 600;">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0576e8;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #3698fb;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #79bbfc;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #bcddfe;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #001233;">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Regal Navy -->
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Regal Navy</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000c19;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #011932;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #01254b;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #023164;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2" style="background-color: #023e7d; border-color: #1d89fc;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5; font-weight: 600;">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0363c9;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #1d89fc;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #68b0fd;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #b4d8fe;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #001233;">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Prussian Blue -->
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Prussian Blue</h3>
                            <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #00040a;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">100</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000714;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">200</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000b1f;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">300</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #000e29;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">400</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded border-2" style="background-color: #001233; border-color: #0052eb;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5; font-weight: 600;">500</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #00328f;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">600</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #0052eb;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">700</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #4788ff;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #9ba3b5;">800</span>
                                </div>
                                <div class="flex flex-col">
                                    <div class="h-16 rounded" style="background-color: #a3c3ff;"></div>
                                    <span style="font-size: 0.75rem; margin-top: 0.25rem; color: #001233;">900</span>
                                </div>
                            </div>
                        </div>

                        <!-- Neutral Colors -->
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Neutral Colors</h3>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div>
                                    <h4 style="font-size: 0.875rem; font-weight: 500; color: #b1b6c0; margin-bottom: 0.5rem;">Twilight Indigo</h4>
                                    <div class="h-12 rounded" style="background-color: #33415c;"></div>
                                </div>
                                <div>
                                    <h4 style="font-size: 0.875rem; font-weight: 500; color: #b1b6c0; margin-bottom: 0.5rem;">Blue Slate</h4>
                                    <div class="h-12 rounded" style="background-color: #5c677d;"></div>
                                </div>
                                <div>
                                    <h4 style="font-size: 0.875rem; font-weight: 500; color: #b1b6c0; margin-bottom: 0.5rem;">Slate Grey</h4>
                                    <div class="h-12 rounded" style="background-color: #7d8597;"></div>
                                </div>
                                <div>
                                    <h4 style="font-size: 0.875rem; font-weight: 500; color: #b1b6c0; margin-bottom: 0.5rem;">Lavender Grey</h4>
                                    <div class="h-12 rounded" style="background-color: #979dac;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Typography -->
                <section class="mb-12">
                    <h2 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #33415c;">
                        Typography
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h1 style="font-size: 3rem; font-weight: 700; color: #c3e0fe; margin-bottom: 0.5rem;">Heading 1</h1>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-5xl font-bold text-smart-blue-900</code>
                        </div>
                        <div>
                            <h2 style="font-size: 2.25rem; font-weight: 700; color: #c3e0fe; margin-bottom: 0.5rem;">Heading 2</h2>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-4xl font-bold text-smart-blue-900</code>
                        </div>
                        <div>
                            <h3 style="font-size: 1.875rem; font-weight: 600; color: #b4d8fe; margin-bottom: 0.5rem;">Heading 3</h3>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-3xl font-semibold text-regal-navy-900</code>
                        </div>
                        <div>
                            <h4 style="font-size: 1.5rem; font-weight: 600; color: #b4d8fe; margin-bottom: 0.5rem;">Heading 4</h4>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-2xl font-semibold text-regal-navy-900</code>
                        </div>
                        <div>
                            <h5 style="font-size: 1.25rem; font-weight: 500; color: #bcddfe; margin-bottom: 0.5rem;">Heading 5</h5>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-xl font-medium text-sapphire-900</code>
                        </div>
                        <div>
                            <h6 style="font-size: 1.125rem; font-weight: 500; color: #bcddfe; margin-bottom: 0.5rem;">Heading 6</h6>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-lg font-medium text-sapphire-900</code>
                        </div>
                        <div>
                            <p style="font-size: 1rem; color: #d0d7e5; margin-bottom: 0.5rem;">Body text - Regular paragraph text that is easy to read and provides good contrast on dark backgrounds.</p>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-base text-twilight-indigo-900</code>
                        </div>
                        <div>
                            <p style="font-size: 0.875rem; color: #b1b6c0; margin-bottom: 0.5rem;">Small text - Used for captions, hints, and secondary information.</p>
                            <code style="font-size: 0.875rem; color: #9ba3b5;">text-sm text-slate-grey-700</code>
                        </div>
                    </div>
                </section>

                <!-- Buttons -->
                <section class="mb-12">
                    <h2 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #33415c;">
                        Buttons
                    </h2>
                    <div class="space-y-6">
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Primary Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg" style="background-color: #0466c8;" onmouseover="this.style.backgroundColor='#0f85fa'" onmouseout="this.style.backgroundColor='#0466c8'">
                                    Smart Blue Primary
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg" style="background-color: #0353a4;" onmouseover="this.style.backgroundColor='#0576e8'" onmouseout="this.style.backgroundColor='#0353a4'">
                                    Sapphire Primary
                                </button>
                                <button class="px-6 py-3 text-white font-semibold rounded-lg transition-colors shadow-lg opacity-50 cursor-not-allowed" style="background-color: #0466c8;" disabled>
                                    Disabled
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Outline Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 border-2 font-semibold rounded-lg transition-colors" style="border-color: #0f85fa; color: #0f85fa; background-color: transparent;" onmouseover="this.style.backgroundColor='#023d78'" onmouseout="this.style.backgroundColor='transparent'">
                                    Outline Smart Blue
                                </button>
                                <button class="px-6 py-3 border-2 font-semibold rounded-lg transition-colors" style="border-color: #0576e8; color: #0576e8; background-color: transparent;" onmouseover="this.style.backgroundColor='#023263'" onmouseout="this.style.backgroundColor='transparent'">
                                    Outline Sapphire
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Ghost Buttons</h3>
                            <div class="flex flex-wrap gap-4">
                                <button class="px-6 py-3 font-semibold rounded-lg transition-colors" style="color: #4ba3fb; background-color: transparent;" onmouseover="this.style.backgroundColor='#023d78'" onmouseout="this.style.backgroundColor='transparent'">
                                    Ghost Smart Blue
                                </button>
                                <button class="px-6 py-3 font-semibold rounded-lg transition-colors" style="color: #3698fb; background-color: transparent;" onmouseover="this.style.backgroundColor='#023263'" onmouseout="this.style.backgroundColor='transparent'">
                                    Ghost Sapphire
                                </button>
                            </div>
                        </div>
                        <div>
                            <h3 style="font-size: 1.25rem; font-weight: 600; color: #c3e0fe; margin-bottom: 1rem;">Button Sizes</h3>
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
                    <h2 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #33415c;">
                        Form Inputs
                    </h2>
                    <div class="space-y-6 max-w-2xl">
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #c3e0fe;">
                                Text Input
                            </label>
                            <input
                                type="text"
                                placeholder="Enter text here"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="background-color: #001233; border: 2px solid #33415c; color: #d0d7e5;"
                                onfocus="this.style.borderColor='#0f85fa'; this.style.boxShadow='0 0 0 3px rgba(15, 133, 250, 0.2)'"
                                onblur="this.style.borderColor='#33415c'; this.style.boxShadow='none'"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #c3e0fe;">
                                Email Input
                            </label>
                            <input
                                type="email"
                                placeholder="you@example.com"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="background-color: #001233; border: 2px solid #33415c; color: #d0d7e5;"
                                onfocus="this.style.borderColor='#0f85fa'; this.style.boxShadow='0 0 0 3px rgba(15, 133, 250, 0.2)'"
                                onblur="this.style.borderColor='#33415c'; this.style.boxShadow='none'"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #c3e0fe;">
                                Password Input
                            </label>
                            <input
                                type="password"
                                placeholder="••••••••"
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors"
                                style="background-color: #001233; border: 2px solid #33415c; color: #d0d7e5;"
                                onfocus="this.style.borderColor='#0f85fa'; this.style.boxShadow='0 0 0 3px rgba(15, 133, 250, 0.2)'"
                                onblur="this.style.borderColor='#33415c'; this.style.boxShadow='none'"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #c3e0fe;">
                                Textarea
                            </label>
                            <textarea
                                rows="4"
                                placeholder="Enter your message here..."
                                class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors resize-none"
                                style="background-color: #001233; border: 2px solid #33415c; color: #d0d7e5;"
                                onfocus="this.style.borderColor='#0f85fa'; this.style.boxShadow='0 0 0 3px rgba(15, 133, 250, 0.2)'"
                                onblur="this.style.borderColor='#33415c'; this.style.boxShadow='none'"
                            ></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-2" style="color: #c3e0fe;">
                                Select Dropdown
                            </label>
                            <select class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors" style="background-color: #001233; border: 2px solid #33415c; color: #d0d7e5;">
                                <option style="background-color: #001233; color: #d0d7e5;">Option 1</option>
                                <option style="background-color: #001233; color: #d0d7e5;">Option 2</option>
                                <option style="background-color: #001233; color: #d0d7e5;">Option 3</option>
                            </select>
                        </div>
                        <div>
                            <label class="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="w-4 h-4 rounded focus:ring-2"
                                    style="accent-color: #0466c8;"
                                >
                                <span style="font-size: 0.875rem; color: #d0d7e5;">Checkbox option</span>
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
                                <span style="font-size: 0.875rem; color: #d0d7e5;">Radio option 1</span>
                            </label>
                            <label class="flex items-center gap-2 mt-2">
                                <input
                                    type="radio"
                                    name="radio-example"
                                    class="w-4 h-4 focus:ring-2"
                                    style="accent-color: #0466c8;"
                                >
                                <span style="font-size: 0.875rem; color: #d0d7e5;">Radio option 2</span>
                            </label>
                        </div>
                    </div>
                </section>

                <!-- Tables -->
                <section class="mb-12">
                    <h2 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #33415c;">
                        Tables
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr style="background-color: #023d78; border-bottom: 2px solid #0466c8;">
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #c3e0fe;">Name</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #c3e0fe;">Email</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #c3e0fe;">Role</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #c3e0fe;">Status</th>
                                    <th class="px-6 py-4 text-left text-sm font-semibold" style="color: #c3e0fe;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="transition-colors" style="border-bottom: 1px solid #33415c;" onmouseover="this.style.backgroundColor='#023d78'" onmouseout="this.style.backgroundColor='transparent'">
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">John Doe</td>
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">john@example.com</td>
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">Admin</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium" style="background-color: #023d78; color: #4ba3fb; border: 1px solid #0466c8;">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors" style="color: #4ba3fb;" onmouseover="this.style.color='#0f85fa'" onmouseout="this.style.color='#4ba3fb'">Edit</button>
                                    </td>
                                </tr>
                                <tr class="transition-colors" style="border-bottom: 1px solid #33415c;" onmouseover="this.style.backgroundColor='#023d78'" onmouseout="this.style.backgroundColor='transparent'">
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">Jane Smith</td>
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">jane@example.com</td>
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium" style="background-color: #023d78; color: #4ba3fb; border: 1px solid #0466c8;">
                                            Active
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors" style="color: #4ba3fb;" onmouseover="this.style.color='#0f85fa'" onmouseout="this.style.color='#4ba3fb'">Edit</button>
                                    </td>
                                </tr>
                                <tr class="transition-colors" style="border-bottom: 1px solid #33415c;" onmouseover="this.style.backgroundColor='#023d78'" onmouseout="this.style.backgroundColor='transparent'">
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">Bob Johnson</td>
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">bob@example.com</td>
                                    <td class="px-6 py-4 text-sm" style="color: #d0d7e5;">User</td>
                                    <td class="px-6 py-4 text-sm">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium" style="background-color: #33415c; color: #9ba3b5; border: 1px solid #5c677d;">
                                            Inactive
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <button class="font-medium transition-colors" style="color: #4ba3fb;" onmouseover="this.style.color='#0f85fa'" onmouseout="this.style.color='#4ba3fb'">Edit</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Links -->
                <section class="mb-12">
                    <h2 style="font-size: 1.875rem; font-weight: 700; color: #c3e0fe; margin-bottom: 1.5rem; padding-bottom: 0.5rem; border-bottom: 2px solid #33415c;">
                        Links
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <a href="#" class="underline font-medium transition-colors" style="color: #4ba3fb;" onmouseover="this.style.color='#0f85fa'" onmouseout="this.style.color='#4ba3fb'">
                                Default Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors" style="color: #4ba3fb;" onmouseover="this.style.color='#0f85fa'" onmouseout="this.style.color='#4ba3fb'">
                                Link without underline
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors" style="color: #3698fb;" onmouseover="this.style.color='#0576e8'" onmouseout="this.style.color='#3698fb'">
                                Sapphire Link
                            </a>
                        </div>
                        <div>
                            <a href="#" class="font-medium transition-colors" style="color: #9ba3b5;" onmouseover="this.style.color='#79859c'" onmouseout="this.style.color='#9ba3b5'">
                                Muted Link
                            </a>
                        </div>
                    </div>
                </section>

                <!-- Navigation -->
                <div class="mt-8 pt-8" style="border-top: 2px solid #33415c;">
                    <a href="{{ route('home') }}" class="inline-flex items-center gap-2 font-medium transition-colors" style="color: #4ba3fb;" onmouseover="this.style.color='#0f85fa'" onmouseout="this.style.color='#4ba3fb'">
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
