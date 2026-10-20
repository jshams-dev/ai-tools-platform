@props([
    'title' => null,
    'heading',
    'subheading' => null,
])

{{-- Sign-in, sign-up and password pages: one centred card over the aurora. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head', ['title' => $title, 'description' => null])
</head>
<body class="h-full bg-white text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    <main class="relative isolate flex min-h-dvh flex-col items-center justify-center px-4 py-10">
        <div class="aurora" aria-hidden="true"><span></span><span></span><span></span></div>

        <a href="{{ route('home') }}" class="group mb-6 flex animate-pop items-center gap-2.5">
            <span class="brand-mark size-10 rounded-xl transition group-hover:rotate-6 group-hover:scale-105">
                <x-icon name="sparkle" class="size-5.5" />
            </span>
            <span class="text-lg font-semibold tracking-tight">{{ config('app.name') }}</span>
        </a>

        <div class="surface w-full max-w-sm animate-rise rounded-3xl p-6 sm:p-8">
            <h1 class="text-xl font-semibold tracking-tight">{{ $heading }}</h1>
            @if ($subheading)
                <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">{{ $subheading }}</p>
            @endif

            @if (session('status'))
                <p class="mt-5 rounded-2xl bg-brand/8 px-4 py-3 text-sm text-brand dark:bg-brand/15 dark:text-brand-soft" role="status">
                    {{ session('status') }}
                </p>
            @endif

            <div class="mt-6">
                {{ $slot }}
            </div>
        </div>

        @isset($footer)
            <p class="mt-6 animate-rise text-sm text-zinc-500 [animation-delay:80ms] dark:text-zinc-400">{{ $footer }}</p>
        @endisset
    </main>
</body>
</html>
