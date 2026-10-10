<x-layouts.auth :title="__('Reset password')" :heading="__('Forgot your password?')" :subheading="__('Enter your email and we will send you a link to choose a new one.')">
    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
        @csrf

        <x-auth.input name="email" type="email" :label="__('Email')" autocomplete="email" required autofocus />

        <button type="submit" class="primary-button mt-2">{{ __('Send reset link') }}</button>
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-medium text-brand hover:underline dark:text-brand-soft">{{ __('Back to sign in') }}</a>
    </x-slot:footer>
</x-layouts.auth>
