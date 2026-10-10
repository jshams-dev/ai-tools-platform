@props([
    'title' => null,
    'description' => null,
    'tools' => [],
    'current' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @if ($description)
        <meta name="description" content="{{ $description }}">
    @endif
    <meta name="theme-color" content="#ffffff">

    {{--
        Applies the theme before anything paints. Without this, a dark-mode
        user sees a white flash on every page load while the CSS and Alpine
        catch up. Same rule as applyTheme() in app.js: a saved choice wins,
        otherwise follow the OS.
    --}}
    <script>
        (() => {
            let mode = 'system';
            try { mode = localStorage.getItem('theme') ?? 'system'; } catch {}
            const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
            document.querySelector('meta[name="theme-color"]').content = dark ? '#09090b' : '#ffffff';
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="h-full bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100"
    x-data="{ sidebar: false }"
    x-on:open-sidebar.window="sidebar = true"
    x-on:keydown.escape.window="sidebar = false"
>
    <div class="flex h-dvh">
        {{-- Backdrop for the off-canvas sidebar on small screens --}}
        <div
            x-cloak
            x-show="sidebar"
            x-transition.opacity
            x-on:click="sidebar = false"
            class="fixed inset-0 z-30 bg-zinc-950/40 backdrop-blur-sm lg:hidden"
        ></div>

        <aside
            :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
            class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-e border-zinc-900/5 bg-zinc-50/95 px-3 py-3 backdrop-blur-xl transition-transform duration-300 ease-out lg:static lg:translate-x-0 lg:bg-zinc-50 dark:border-white/5 dark:bg-zinc-900/95 lg:dark:bg-zinc-900/60"
        >
            <a href="{{ route('home') }}" class="group flex items-center gap-2.5 rounded-xl px-2 py-2">
                <span class="brand-mark size-8 rounded-lg transition group-hover:rotate-6 group-hover:scale-105">
                    <x-icon name="sparkle" class="size-4.5" />
                </span>
                <span class="text-[15px] font-semibold tracking-tight">{{ config('app.name') }}</span>
            </a>

            <button
                type="button"
                x-on:click="Livewire.dispatch('new-chat'); sidebar = false"
                class="mt-3 flex items-center gap-2.5 rounded-xl border border-zinc-900/8 bg-white px-3 py-2.5 text-sm font-medium text-zinc-700 shadow-xs transition hover:border-brand/30 hover:shadow-md hover:shadow-brand/10 active:scale-[0.98] dark:border-white/8 dark:bg-white/5 dark:text-zinc-200"
            >
                <x-icon name="pencil-square" class="size-4.5 text-brand" />
                {{ __('New chat') }}
            </button>

            <p class="mt-6 px-3 pb-2 text-xs font-medium text-zinc-500">{{ __('Tools') }}</p>

            <nav class="flex flex-col gap-0.5">
                @foreach ($tools as $item)
                    <a
                        href="{{ route('tools.show', $item->slug) }}"
                        @class([
                            'group relative flex flex-col rounded-xl px-3 py-2 transition',
                            'bg-white shadow-xs dark:bg-white/8' => $item->slug === $current,
                            'hover:bg-zinc-900/4 dark:hover:bg-white/5' => $item->slug !== $current,
                        ])
                    >
                        @if ($item->slug === $current)
                            {{-- Active marker: a short gradient bar on the leading edge --}}
                            <span class="absolute inset-y-2 start-0 w-0.5 rounded-full bg-brand-gradient"></span>
                        @endif
                        <span class="text-sm font-medium">{{ $item->name }}</span>
                        <span class="line-clamp-1 text-xs text-zinc-500">{{ $item->description }}</span>
                    </a>
                @endforeach
            </nav>

            {{-- Profile: opens a menu. Profile and settings pages come with accounts. --}}
            <div x-data="{ open: false }" x-on:keydown.escape.stop="open = false" class="relative mt-auto">
                <div
                    x-cloak
                    x-show="open"
                    x-on:click.outside="open = false"
                    x-transition.origin.bottom.left
                    class="menu bottom-full left-0 mb-2 w-full"
                >
                    <a href="#" class="menu-item pointer-events-none opacity-50" aria-disabled="true">
                        <span class="flex items-center gap-2.5"><x-icon name="user" class="size-4.5" />{{ __('Profile') }}</span>
                        <span class="text-[11px]">{{ __('Soon') }}</span>
                    </a>
                    <a href="#" class="menu-item pointer-events-none opacity-50" aria-disabled="true">
                        <span class="flex items-center gap-2.5"><x-icon name="adjustments" class="size-4.5" />{{ __('Settings') }}</span>
                        <span class="text-[11px]">{{ __('Soon') }}</span>
                    </a>

                    <div class="my-1.5 border-t border-zinc-900/5 dark:border-white/5"></div>

                    <p class="px-2.5 pt-1 pb-2 text-xs font-medium text-zinc-500">{{ __('Appearance') }}</p>

                    {{-- Segmented control; "System" follows the OS and is the default --}}
                    <div class="mx-1 mb-1 grid grid-cols-3 gap-1 rounded-xl bg-zinc-900/5 p-1 dark:bg-white/5" role="radiogroup" aria-label="{{ __('Appearance') }}">
                        @foreach (['system' => ['desktop', __('System')], 'light' => ['sun', __('Light')], 'dark' => ['moon', __('Dark')]] as $mode => [$icon, $label])
                            <button
                                type="button"
                                role="radio"
                                x-on:click="$store.theme.set(@js($mode))"
                                :aria-checked="$store.theme.mode === @js($mode)"
                                :class="$store.theme.mode === @js($mode)
                                    ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
                                    : 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200'"
                                class="flex flex-col items-center gap-1 rounded-lg py-1.5 text-[11px] font-medium transition"
                            >
                                <x-icon :name="$icon" class="size-4" />
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <button
                    type="button"
                    x-on:click="open = ! open"
                    :aria-expanded="open"
                    class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start transition hover:bg-zinc-900/4 dark:hover:bg-white/5"
                >
                    <span class="grid size-8 place-items-center rounded-full bg-linear-to-br from-zinc-200 to-zinc-300 text-xs font-semibold text-zinc-600 dark:from-zinc-700 dark:to-zinc-800 dark:text-zinc-200">G</span>
                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block text-sm font-medium">{{ __('Guest') }}</span>
                        <span class="block text-xs text-zinc-500">{{ __('Free plan') }}</span>
                    </span>
                    <x-icon name="chevron-up-down" class="size-4 text-zinc-400" />
                </button>
            </div>
        </aside>

        <main class="flex min-w-0 flex-1 flex-col">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
