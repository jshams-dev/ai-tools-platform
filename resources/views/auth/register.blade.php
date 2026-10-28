<x-layouts.auth :title="__('Create account')" :heading="__('Create your account')" :subheading="__('Free to start. Your chats are saved to your account.')">
    <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-4">
        @csrf

        <x-auth.input name="name" :label="__('Name')" autocomplete="name" required autofocus />
        <x-auth.input name="email" type="email" :label="__('Email')" autocomplete="email" required />
        <x-auth.input name="password" type="password" :label="__('Password')" autocomplete="new-password" required />
        <x-auth.input name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" required />

        <button type="submit" class="primary-button mt-2">{{ __('Create account') }}</button>
    </form>

    <x-slot:footer>
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" class="font-medium text-brand hover:underline dark:text-brand-soft">{{ __('Sign in') }}</a>
    </x-slot:footer>
</x-layouts.auth>
