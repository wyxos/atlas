<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Reverb Test - {{ config('app.name', 'Atlas') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @endif

        @include('partials.favicons')
    </head>
    <body class="bg-prussian-blue-700 text-twilight-indigo-900 min-h-screen">
        <div id="app"></div>
        <main class="mx-auto flex min-h-screen w-full max-w-4xl flex-col gap-6 p-6">
            <header class="rounded-lg border border-prussian-blue-500 bg-prussian-blue-600 p-6 text-smart-blue-900 shadow-lg">
                <h1 class="text-2xl font-semibold">Reverb Integration Test</h1>
                <p class="mt-2 text-blue-slate-300">
                    This page listens to the <span class="font-semibold text-white">downloads</span> private channel and
                    triggers demo events to verify Reverb is wired up.
                </p>
            </header>

            <section class="rounded-lg border border-prussian-blue-500 bg-prussian-blue-600 p-6 text-smart-blue-900 shadow-lg">
                <div class="flex flex-col gap-4 sm:flex-row">
                    <button id="send-progress" type="button"
                        class="inline-flex items-center justify-center rounded bg-smart-blue-500 px-4 py-2 text-white transition-colors hover:bg-smart-blue-600">
                        Send Progress Update
                    </button>
                    <button id="send-queued" type="button"
                        class="inline-flex items-center justify-center rounded bg-sapphire-500 px-4 py-2 text-white transition-colors hover:bg-sapphire-600">
                        Send Queued Event
                    </button>
                </div>
            </section>

            <section class="rounded-lg border border-prussian-blue-500 bg-prussian-blue-600 p-6 text-smart-blue-900 shadow-lg">
                <h2 class="text-lg font-semibold">Live Events</h2>
                <ul id="event-log" class="mt-4 flex flex-col gap-2 text-sm text-blue-slate-200"></ul>
                <p id="echo-status" class="mt-4 text-sm text-blue-slate-300"></p>
            </section>
        </main>

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const eventLog = document.getElementById('event-log');
                const echoStatus = document.getElementById('echo-status');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const triggerUrl = @json(route('reverb.test.trigger'));

                function appendLog(message) {
                    const item = document.createElement('li');
                    item.textContent = `${new Date().toLocaleTimeString()} — ${message}`;
                    eventLog?.prepend(item);
                }

                async function sendEvent(payload = {}) {
                    try {
                        const response = await fetch(triggerUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken ?? '',
                            },
                            body: JSON.stringify(payload),
                        });

                        if (response.ok) {
                            appendLog('Trigger request sent');
                        } else {
                            appendLog(`Trigger failed (${response.status})`);
                        }
                    } catch (error) {
                        appendLog(`Trigger error: ${error}`);
                    }
                }

                document.getElementById('send-progress')?.addEventListener('click', () => {
                    sendEvent({ status: 'processing', percent: Math.floor(Math.random() * 100) + 1 });
                });

                document.getElementById('send-queued')?.addEventListener('click', () => {
                    sendEvent({ status: 'queued', percent: 0 });
                });

                const echo = window.Echo;
                if (!echo) {
                    echoStatus.textContent = 'Echo not available. Make sure Vite assets are built and Reverb is running.';
                    return;
                }

                echoStatus.textContent = 'Echo connected. Listening on private channel: downloads.';
                const channel = echo.private('downloads');
                channel.listen('.DownloadTransferQueued', () => {
                    appendLog('Received DownloadTransferQueued');
                });
                channel.listen('.DownloadTransferProgressUpdated', (payload) => {
                    appendLog(`Progress update: ${payload.status} (${payload.percent}%)`);
                });
            });
        </script>
    </body>
</html>
