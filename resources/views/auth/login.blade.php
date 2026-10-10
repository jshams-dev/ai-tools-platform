<x-layouts.auth :title="__('Sign in')" :heading="__('Welcome back')" :subheading="__('Sign in to keep using your tools and chats.')">
    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
        @csrf

        <x-auth.input name="email" type="email" :label="__('Email')" autocomplete="email" required autofocus />

        <div>
            <x-auth.input name="password" type="password" :label="__('Password')" autocomplete="current-password" required />
            <a href="{{ route('password.request') }}" class="mt-2 inline-block text-sm text-brand hover:underline dark:text-brand-soft">
                {{ __('Forgot your password?') }}
            </a>
        </div>

        <label class="flex items-center gap-2.5 text-sm text-zinc-600 dark:text-zinc-300">
            <input type="checkbox" name="remember" class="size-4 rounded accent-brand">
            {{ __('Keep me signed in') }}
        </label>

        <button type="submit" class="primary-button mt-2">{{ __('Sign in') }}</button>
    </form>

    <x-slot:footer>
        {{ __('New here?') }}
        <a href="{{ route('register') }}" class="font-medium text-brand hover:underline dark:text-brand-soft">{{ __('Create a free account') }}</a>
    </x-slot:footer>
</x-layouts.auth>
