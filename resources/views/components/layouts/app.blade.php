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
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#09090b" media="(prefers-color-scheme: dark)">

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
            class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col bg-zinc-50 px-3 py-3 transition-transform duration-200 lg:static lg:translate-x-0 dark:bg-zinc-900"
        >
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 rounded-xl px-2 py-2">
                <span class="brand-mark size-8 rounded-lg">
                    <x-icon name="sparkle" class="size-4.5" />
                </span>
                <span class="text-[15px] font-semibold tracking-tight">{{ config('app.name') }}</span>
            </a>

            <button
                type="button"
                x-on:click="Livewire.dispatch('new-chat'); sidebar = false"
                class="mt-3 flex items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-200/60 dark:text-zinc-300 dark:hover:bg-zinc-800"
            >
                <x-icon name="pencil-square" class="size-4.5" />
                {{ __('New chat') }}
            </button>

            <p class="mt-6 px-3 pb-2 text-xs font-medium text-zinc-500">{{ __('Tools') }}</p>

            <nav class="flex flex-col gap-0.5">
                @foreach ($tools as $item)
                    <a
                        href="{{ route('tools.show', $item->slug) }}"
                        @class([
                            'group flex flex-col rounded-xl px-3 py-2 transition',
                            'bg-zinc-200/70 dark:bg-zinc-800' => $item->slug === $current,
                            'hover:bg-zinc-200/50 dark:hover:bg-zinc-800/60' => $item->slug !== $current,
                        ])
                    >
                        <span class="text-sm font-medium">{{ $item->name }}</span>
                        <span class="line-clamp-1 text-xs text-zinc-500">{{ $item->description }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="mt-auto flex items-center gap-3 rounded-xl px-3 py-2.5">
                <span class="grid size-8 place-items-center rounded-full bg-zinc-200 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">G</span>
                <div class="leading-tight">
                    <p class="text-sm font-medium">{{ __('Guest') }}</p>
                    <p class="text-xs text-zinc-500">{{ __('Free plan') }}</p>
                </div>
            </div>
        </aside>

        <main class="flex min-w-0 flex-1 flex-col">
            {{ $slot }}
        </main>
    </div>
</body>
</html>
