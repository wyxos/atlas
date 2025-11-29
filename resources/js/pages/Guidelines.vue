<script setup lang="ts">
import { ArrowLeft, Trash2, CheckCircle2 } from 'lucide-vue-next';
import PageLayout from '../components/PageLayout.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import Section from '../components/ui/Section.vue';
import Button from '../components/ui/Button.vue';
import Badge from '../components/ui/Badge.vue';
import FormInput from '../components/ui/FormInput.vue';
import Link from '../components/ui/Link.vue';
import { useRouter } from 'vue-router';

const router = useRouter();

const colorPalettes = [
    {
        name: 'Smart Blue',
        classes: {
            100: 'bg-smart-blue-100',
            200: 'bg-smart-blue-200',
            300: 'bg-smart-blue-700',
            400: 'bg-smart-blue-400',
            500: 'bg-smart-blue-500',
            600: 'bg-smart-blue-600',
            700: 'bg-smart-blue-700',
            800: 'bg-smart-blue-800',
            900: 'bg-smart-blue-900',
        },
        borderClass: 'border-smart-blue-700',
    },
    {
        name: 'Sapphire',
        classes: {
            100: 'bg-sapphire-100',
            200: 'bg-sapphire-200',
            300: 'bg-sapphire-300',
            400: 'bg-sapphire-400',
            500: 'bg-sapphire-500',
            600: 'bg-sapphire-600',
            700: 'bg-sapphire-700',
            800: 'bg-sapphire-800',
            900: 'bg-sapphire-900',
        },
        borderClass: 'border-sapphire-700',
    },
    {
        name: 'Regal Navy',
        classes: {
            100: 'bg-regal-navy-100',
            200: 'bg-regal-navy-200',
            300: 'bg-regal-navy-300',
            400: 'bg-regal-navy-400',
            500: 'bg-regal-navy-500',
            600: 'bg-regal-navy-600',
            700: 'bg-regal-navy-700',
            800: 'bg-regal-navy-800',
            900: 'bg-regal-navy-900',
        },
        borderClass: 'border-regal-navy-700',
    },
    {
        name: 'Prussian Blue',
        classes: {
            100: 'bg-prussian-blue-100',
            200: 'bg-prussian-blue-200',
            300: 'bg-prussian-blue-300',
            400: 'bg-prussian-blue-400',
            500: 'bg-prussian-blue-500',
            600: 'bg-prussian-blue-400',
            700: 'bg-prussian-blue-700',
            800: 'bg-prussian-blue-800',
            900: 'bg-prussian-blue-900',
        },
        borderClass: 'border-prussian-blue-700',
    },
    {
        name: 'Danger/Warning',
        classes: {
            100: 'bg-danger-100',
            200: 'bg-danger-200',
            300: 'bg-danger-700',
            400: 'bg-danger-400',
            500: 'bg-danger-500',
            600: 'bg-danger-400',
            700: 'bg-danger-700',
            800: 'bg-danger-800',
            900: 'bg-danger-900',
        },
        borderClass: 'border-danger-300',
    },
    {
        name: 'Success',
        classes: {
            100: 'bg-success-100',
            200: 'bg-success-200',
            300: 'bg-success-300',
            400: 'bg-success-400',
            500: 'bg-success-500',
            600: 'bg-success-600',
            700: 'bg-success-700',
            800: 'bg-success-800',
            900: 'bg-success-900',
        },
        borderClass: 'border-success-300',
    },
];

const neutralColors = [
    { name: 'Twilight Indigo', class: 'bg-twilight-indigo-500' },
    { name: 'Blue Slate', class: 'bg-blue-slate-500' },
    { name: 'Slate Grey', class: 'bg-slate-grey-500' },
    { name: 'Lavender Grey', class: 'bg-lavender-grey-500' },
];

const typographyExamples = [
    {
        element: 'h1',
        classes: 'text-5xl font-bold text-smart-blue-100',
        text: 'Heading 1',
    },
    {
        element: 'h2',
        classes: 'text-4xl font-bold text-smart-blue-100',
        text: 'Heading 2',
    },
    {
        element: 'h3',
        classes: 'text-3xl font-semibold text-regal-navy-100',
        text: 'Heading 3',
    },
    {
        element: 'h4',
        classes: 'text-2xl font-semibold text-regal-navy-100',
        text: 'Heading 4',
    },
    {
        element: 'h5',
        classes: 'text-xl font-medium text-sapphire-100',
        text: 'Heading 5',
    },
    {
        element: 'h6',
        classes: 'text-lg font-medium text-sapphire-100',
        text: 'Heading 6',
    },
    {
        element: 'p',
        classes: 'text-base text-twilight-indigo-100',
        text: 'Body text - Regular paragraph text that is easy to read and provides good contrast on dark backgrounds.',
    },
    {
        element: 'p',
        classes: 'text-sm text-slate-grey-300',
        text: 'Small text - Used for captions, hints, and secondary information.',
    },
];

const tableData = [
    {
        name: 'John Doe',
        email: 'john@example.com',
        role: 'Admin',
        status: 'active',
    },
    {
        name: 'Jane Smith',
        email: 'jane@example.com',
        role: 'User',
        status: 'active',
    },
    {
        name: 'Bob Johnson',
        email: 'bob@example.com',
        role: 'User',
        status: 'inactive',
    },
];

function goHome() {
    router.push('/');
}
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <PageHeader
                title="UI/UX Guidelines"
                subtitle="Design system and component showcase for Atlas"
            />

            <!-- Color Palette -->
            <Section title="Color Palette">
                <div class="space-y-8">
                    <div v-for="palette in colorPalettes" :key="palette.name">
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            {{ palette.name }}
                        </h3>
                        <div class="grid grid-cols-5 md:grid-cols-10 gap-2">
                            <div
                                v-for="shade in [100, 200, 300, 400, 500, 600, 700, 800, 900]"
                                :key="shade"
                                class="flex flex-col"
                            >
                                <div
                                    :class="[
                                        'h-16 rounded',
                                        palette.classes[shade as keyof typeof palette.classes],
                                        shade === 500 ? 'border-2 ' + palette.borderClass : '',
                                    ]"
                                ></div>
                                <span
                                    :class="[
                                        'text-xs mt-1',
                                        shade === 500 ? 'font-semibold' : '',
                                        shade === 900
                                            ? 'text-prussian-blue-500'
                                            : 'text-twilight-indigo-100',
                                    ]"
                                >
                                    {{ shade }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Neutral Colors -->
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Neutral Colors
                        </h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div v-for="color in neutralColors" :key="color.name">
                                <h4 class="text-sm font-medium text-slate-grey-300 mb-2">
                                    {{ color.name }}
                                </h4>
                                <div :class="`h-12 rounded ${color.class}`"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Typography -->
            <Section title="Typography">
                <div class="space-y-6">
                    <div v-for="(example, index) in typographyExamples" :key="index">
                        <component
                            :is="example.element"
                            :class="example.classes + ' mb-2'"
                        >
                            {{ example.text }}
                        </component>
                        <code class="text-sm text-twilight-indigo-100">
                            {{ example.classes }}
                        </code>
                    </div>
                </div>
            </Section>

            <!-- Buttons -->
            <Section title="Buttons">
                <div class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Primary Buttons
                        </h3>
                        <div class="flex flex-wrap gap-4">
                            <Button variant="default" class="bg-smart-blue-500 hover:bg-smart-blue-400">
                                Smart Blue Primary
                            </Button>
                            <Button variant="default" class="bg-sapphire-500 hover:bg-sapphire-400">
                                Sapphire Primary
                            </Button>
                            <Button variant="default" disabled class="bg-smart-blue-500">
                                Disabled
                            </Button>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Outline Buttons
                        </h3>
                        <div class="flex flex-wrap gap-4">
                            <Button
                                variant="outline"
                                class="border-smart-blue-400 text-smart-blue-400 bg-transparent hover:bg-smart-blue-700"
                            >
                                Outline Smart Blue
                            </Button>
                            <Button
                                variant="outline"
                                class="border-sapphire-600 text-sapphire-600 bg-transparent hover:bg-sapphire-300"
                            >
                                Outline Sapphire
                            </Button>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Ghost Buttons
                        </h3>
                        <div class="flex flex-wrap gap-4">
                            <Button
                                variant="ghost"
                                class="text-smart-blue-300 bg-transparent hover:bg-smart-blue-700"
                            >
                                Ghost Smart Blue
                            </Button>
                            <Button
                                variant="ghost"
                                class="text-sapphire-300 bg-transparent hover:bg-sapphire-700"
                            >
                                Ghost Sapphire
                            </Button>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Button Sizes
                        </h3>
                        <div class="flex flex-wrap items-center gap-4">
                            <Button size="sm" variant="default" class="bg-smart-blue-500">
                                Small
                            </Button>
                            <Button size="default" variant="default" class="bg-smart-blue-500">
                                Medium
                            </Button>
                            <Button size="lg" variant="default" class="bg-smart-blue-500">
                                Large
                            </Button>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Danger Buttons
                        </h3>
                        <div class="flex flex-wrap gap-4">
                            <Button
                                variant="default"
                                class="bg-danger-400 hover:bg-danger-700"
                            >
                                Danger Primary
                            </Button>
                            <Button
                                variant="outline"
                                class="border-danger-400 text-danger-400 bg-transparent hover:bg-danger-700"
                            >
                                Danger Outline
                            </Button>
                            <Button
                                variant="ghost"
                                class="text-danger-300 bg-transparent hover:bg-danger-700"
                            >
                                Danger Ghost
                            </Button>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Icon Buttons
                        </h3>
                        <div class="space-y-6">
                            <div>
                                <h4 class="text-lg font-medium text-regal-navy-100 mb-3">
                                    Primary (Filled)
                                </h4>
                                <div class="flex flex-wrap gap-4">
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer bg-smart-blue-500 border-2 border-smart-blue-400 text-white hover:bg-smart-blue-400"
                                    >
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer bg-danger-400 border-2 border-danger-300 text-white hover:bg-danger-700"
                                    >
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer bg-success-500 border-2 border-success-400 text-white hover:bg-success-400"
                                    >
                                        <CheckCircle2 :size="20" />
                                    </button>
                                </div>
                                <code class="block mt-2 text-xs text-twilight-indigo-100">
                                    h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg bg-smart-blue-500 border-2 border-smart-blue-400 text-white hover:bg-smart-blue-400
                                </code>
                            </div>
                            <div>
                                <h4 class="text-lg font-medium text-regal-navy-100 mb-3">
                                    Outline
                                </h4>
                                <div class="flex flex-wrap gap-4">
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer border-2 border-smart-blue-400 text-smart-blue-400 bg-transparent hover:bg-smart-blue-500 hover:border-smart-blue-400 hover:text-white"
                                    >
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer border-2 border-danger-300 text-danger-300 bg-transparent hover:bg-danger-500 hover:border-danger-400 hover:text-white"
                                    >
                                        <Trash2 :size="20" />
                                    </button>
                                </div>
                                <code class="block mt-2 text-xs text-twilight-indigo-100">
                                    h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg border-2 border-smart-blue-400 text-smart-blue-400 bg-transparent hover:bg-smart-blue-500 hover:border-smart-blue-400 hover:text-white
                                </code>
                            </div>
                            <div>
                                <h4 class="text-lg font-medium text-regal-navy-100 mb-3">
                                    Ghost
                                </h4>
                                <div class="flex flex-wrap gap-4">
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer text-smart-blue-300 bg-transparent hover:bg-smart-blue-700"
                                    >
                                        <Trash2 :size="20" />
                                    </button>
                                    <button
                                        class="h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg transition-all cursor-pointer text-danger-300 bg-transparent hover:bg-danger-700"
                                    >
                                        <Trash2 :size="20" />
                                    </button>
                                </div>
                                <code class="block mt-2 text-xs text-twilight-indigo-100">
                                    h-10 w-10 md:h-10 md:w-10 flex items-center justify-center rounded-lg text-smart-blue-300 bg-transparent hover:bg-smart-blue-700
                                </code>
                            </div>
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Badges & Tags -->
            <Section title="Badges & Tags">
                <div class="space-y-4">
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Status Badges
                        </h3>
                        <div class="flex flex-wrap gap-4 items-center">
                            <Badge variant="verified" icon-only />
                            <Badge variant="active">Active</Badge>
                            <Badge variant="inactive">Inactive</Badge>
                            <Badge variant="error">Error</Badge>
                            <Badge variant="pending">Pending</Badge>
                        </div>
                        <code class="block mt-2 text-xs text-twilight-indigo-100">
                            Verified: inline-flex items-center justify-center p-1.5 rounded-sm bg-success-300 border-success-500 (icon only). Other badges: px-3 py-1 rounded-sm text-xs font-medium bg-* border-*
                        </code>
                    </div>
                </div>
            </Section>

            <!-- Form Inputs -->
            <Section title="Form Inputs">
                <div class="space-y-6 max-w-2xl">
                    <FormInput
                        type="text"
                        placeholder="Enter text here"
                    >
                        <template #label>Text Input</template>
                    </FormInput>
                    <FormInput
                        type="email"
                        placeholder="you@example.com"
                    >
                        <template #label>Email Input</template>
                    </FormInput>
                    <FormInput
                        type="password"
                        placeholder="••••••••"
                    >
                        <template #label>Password Input</template>
                    </FormInput>
                    <div>
                        <label class="block text-sm font-medium mb-2 text-smart-blue-100">
                            Textarea
                        </label>
                        <textarea
                            rows="4"
                            placeholder="Enter your message here..."
                            class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors resize-none bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-100 focus:border-smart-blue-400 focus:ring-smart-blue-600/20"
                        ></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2 text-smart-blue-100">
                            Select Dropdown
                        </label>
                        <select
                            class="w-full px-4 py-3 rounded-lg focus:ring-2 focus:outline-none transition-colors bg-prussian-blue-500 border-2 border-twilight-indigo-500 text-twilight-indigo-100 focus:border-smart-blue-400 focus:ring-smart-blue-600/20"
                        >
                            <option class="bg-prussian-blue-500 text-twilight-indigo-100">
                                Option 1
                            </option>
                            <option class="bg-prussian-blue-500 text-twilight-indigo-100">
                                Option 2
                            </option>
                            <option class="bg-prussian-blue-500 text-twilight-indigo-100">
                                Option 3
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                class="w-4 h-4 rounded focus:ring-2 accent-smart-blue-500"
                            />
                            <span class="text-sm text-twilight-indigo-100">
                                Checkbox option
                            </span>
                        </label>
                    </div>
                    <div>
                        <label class="flex items-center gap-2">
                            <input
                                type="radio"
                                name="radio-example"
                                class="w-4 h-4 focus:ring-2 accent-smart-blue-500"
                            />
                            <span class="text-sm text-twilight-indigo-100">
                                Radio option 1
                            </span>
                        </label>
                        <label class="flex items-center gap-2 mt-2">
                            <input
                                type="radio"
                                name="radio-example"
                                class="w-4 h-4 focus:ring-2 accent-smart-blue-500"
                            />
                            <span class="text-sm text-twilight-indigo-100">
                                Radio option 2
                            </span>
                        </label>
                    </div>
                </div>
            </Section>

            <!-- Tables -->
            <Section title="Tables">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse">
                        <thead>
                            <tr class="bg-smart-blue-700 border-b-2 border-smart-blue-500">
                                <th
                                    class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100"
                                >
                                    Name
                                </th>
                                <th
                                    class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100"
                                >
                                    Email
                                </th>
                                <th
                                    class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100"
                                >
                                    Role
                                </th>
                                <th
                                    class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100"
                                >
                                    Status
                                </th>
                                <th
                                    class="px-6 py-4 text-left text-sm font-semibold text-smart-blue-100"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in tableData"
                                :key="index"
                                class="transition-colors border-b border-twilight-indigo-500 hover:bg-smart-blue-700"
                            >
                                <td class="px-6 py-4 text-sm text-twilight-indigo-100">
                                    {{ row.name }}
                                </td>
                                <td class="px-6 py-4 text-sm text-twilight-indigo-100">
                                    {{ row.email }}
                                </td>
                                <td class="px-6 py-4 text-sm text-twilight-indigo-100">
                                    {{ row.role }}
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <Badge :variant="row.status === 'active' ? 'active' : 'inactive'">
                                        {{ row.status === 'active' ? 'Active' : 'Inactive' }}
                                    </Badge>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <button
                                        class="font-medium transition-colors cursor-pointer text-smart-blue-300 hover:text-smart-blue-400"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Section>

            <!-- Links -->
            <Section title="Links">
                <div class="space-y-4">
                    <div>
                        <Link variant="default" href="#">Default Link</Link>
                    </div>
                    <div>
                        <Link variant="no-underline" href="#">
                            Link without underline
                        </Link>
                    </div>
                    <div>
                        <Link variant="sapphire" href="#">Sapphire Link</Link>
                    </div>
                    <div>
                        <Link variant="muted" href="#">Muted Link</Link>
                    </div>
                </div>
            </Section>

            <!-- Pagination -->
            <Section title="Pagination">
                <div class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Pagination Controls
                        </h3>
                        <div class="bg-prussian-blue-500 rounded-lg p-6">
                            <div class="flex justify-end">
                                <nav
                                    class="flex items-center gap-2"
                                    aria-label="Pagination"
                                >
                                    <button
                                        disabled
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-500 cursor-not-allowed opacity-40 transition-all"
                                    >
                                        <ArrowLeft :size="20" />
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-smart-blue-400 rounded-lg bg-smart-blue-500 text-white font-semibold transition-all"
                                        aria-current="page"
                                    >
                                        1
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 transition-all cursor-pointer"
                                    >
                                        2
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 transition-all cursor-pointer"
                                    >
                                        3
                                    </button>
                                    <button
                                        class="flex items-center justify-center min-w-[2.5rem] h-10 px-3 border border-twilight-indigo-500 rounded-lg bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 transition-all cursor-pointer"
                                    >
                                        <ArrowLeft :size="20" class="rotate-180" />
                                    </button>
                                </nav>
                            </div>
                        </div>
                        <div class="mt-4 p-4 bg-prussian-blue-500 rounded-lg">
                            <p class="text-sm text-twilight-indigo-100 mb-2">
                                <strong>Tailwind Classes:</strong>
                            </p>
                            <code
                                class="text-xs text-smart-blue-300 block whitespace-pre-wrap"
                            >
flex items-center justify-center min-w-[2.5rem] h-10 px-3 border rounded-lg transition-all
border-smart-blue-400 bg-smart-blue-500 text-white font-semibold (current page)
border-twilight-indigo-500 bg-transparent text-twilight-indigo-100 hover:bg-smart-blue-700 hover:border-smart-blue-400 hover:text-smart-blue-100 (page buttons)
opacity-40 cursor-not-allowed (disabled)</code
                            >
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Scrollbars -->
            <Section title="Scrollbars">
                <div class="space-y-6">
                    <div>
                        <h3 class="text-xl font-semibold text-smart-blue-100 mb-4">
                            Custom Scrollbar Styling
                        </h3>
                        <p class="text-base text-twilight-indigo-100 mb-4">
                            Scrollbars are styled to match the dark theme. The scrollbar track uses
                            Twilight Indigo 300, the thumb uses Twilight Indigo 600, and on hover it
                            changes to Smart Blue 600.
                        </p>
                        <div class="bg-prussian-blue-500 rounded-lg p-6">
                            <div class="h-64 overflow-y-auto pr-4">
                                <div class="space-y-4">
                                    <p class="text-base text-twilight-indigo-100">
                                        This is a scrollable container demonstrating the custom
                                        scrollbar styling. Scroll down to see the scrollbar in action.
                                    </p>
                                    <div
                                        v-for="i in 20"
                                        :key="i"
                                        class="p-4 bg-prussian-blue-400 rounded-lg"
                                    >
                                        <h4 class="text-lg font-semibold text-smart-blue-100 mb-2">
                                            Item {{ i }}
                                        </h4>
                                        <p class="text-sm text-twilight-indigo-100">
                                            This is content item {{ i }}. The scrollbar on the right
                                            demonstrates the custom styling with Twilight Indigo
                                            colors that match the dark theme.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4 p-4 bg-prussian-blue-500 rounded-lg">
                            <p class="text-sm text-twilight-indigo-100 mb-2">
                                <strong>CSS Properties:</strong>
                            </p>
                            <code
                                class="text-xs text-smart-blue-300 block whitespace-pre-wrap"
                            >
/* Webkit browsers (Chrome, Safari, Edge) */
::-webkit-scrollbar {
    width: 12px;
    height: 12px;
}

::-webkit-scrollbar-track {
    background: #1e2737; /* twilight-indigo-300 */
    border-radius: 6px;
}

::-webkit-scrollbar-thumb {
    background: #4d628b; /* twilight-indigo-600 */
    border-radius: 6px;
    border: 2px solid #1e2737; /* twilight-indigo-300 */
}

::-webkit-scrollbar-thumb:hover {
    background: #0f85fa; /* smart-blue-600 */
}

/* Firefox */
* {
    scrollbar-width: thin;
    scrollbar-color: #4d628b #1e2737; /* thumb track */
}</code
                            >
                        </div>
                    </div>
                </div>
            </Section>

            <!-- Navigation -->
            <div class="mt-8 pt-8 border-t-2 border-twilight-indigo-500">
                <Link
                    variant="no-underline"
                    href="#"
                    @click.prevent="goHome"
                    class="inline-flex items-center gap-2"
                >
                    <ArrowLeft :size="20" />
                    Back to Home
                </Link>
            </div>
        </div>
    </PageLayout>
</template>

