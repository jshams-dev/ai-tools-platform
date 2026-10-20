{{--
    Shown in place of a reply when a guest has used today's free messages.
    Their message stays in the box and comes back after they sign in.
--}}
<div class="surface mb-3 animate-rise rounded-3xl p-4" role="status">
    <div class="flex items-start gap-3">
        <span class="brand-mark size-9 rounded-xl">
            <x-icon name="sparkle" class="size-4.5" />
        </span>
        <div class="min-w-0">
            <p class="font-semibold tracking-tight">{{ __("You've used today's free messages") }}</p>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sign in to keep going. Your message will be waiting.') }}</p>
        </div>
    </div>

    <div class="mt-3.5 flex gap-2">
        <a href="{{ route('register') }}" class="primary-button h-10 flex-1 text-sm">{{ __('Create free account') }}</a>
        <a href="{{ route('login') }}" class="pill h-10 flex-1 justify-center rounded-2xl text-sm">{{ __('Sign in') }}</a>
    </div>
</div>
