<x-layouts.auth :title="__('Reset password')" :heading="__('Choose a new password')">
    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-auth.input name="email" type="email" :label="__('Email')" :value="$request->email" autocomplete="email" required />
        <x-auth.input name="password" type="password" :label="__('New password')" autocomplete="new-password" required autofocus />
        <x-auth.input name="password_confirmation" type="password" :label="__('Confirm password')" autocomplete="new-password" required />

        <button type="submit" class="primary-button mt-2">{{ __('Save password') }}</button>
    </form>
</x-layouts.auth>
