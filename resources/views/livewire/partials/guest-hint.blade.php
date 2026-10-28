{{-- Under the message box for guests: what is left today, and why to sign in. --}}
<p class="mt-2 text-center text-xs text-zinc-500">
    {{ trans_choice('{0} No free messages left today.|{1} 1 free message left today.|[2,*] :count free messages left today.', $guestRunsLeft) }}
    <a href="{{ route('login') }}" class="font-medium text-brand hover:underline dark:text-brand-soft">{{ __('Sign in to save your chats') }}</a>
</p>
