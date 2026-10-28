<div x-data="workspace" class="relative isolate flex h-full min-h-0 flex-col">
    {{-- Top bar --}}
    <header class="flex h-14 shrink-0 items-center gap-2 px-3 sm:px-5">
        <button
            type="button"
            x-on:click="$dispatch('open-sidebar')"
            class="icon-button lg:hidden"
            aria-label="{{ __('Open menu') }}"
        >
            <x-icon name="bars" class="size-5" />
        </button>

        <h2 class="text-[15px] font-semibold tracking-tight">{{ $tool->name }}</h2>

        @if ($messages !== [])
            <button
                type="button"
                wire:click="newChat"
                class="icon-button ml-auto"
                title="{{ __('New chat') }}"
                aria-label="{{ __('New chat') }}"
            >
                <x-icon name="pencil-square" class="size-5" />
            </button>
        @endif
    </header>

    @if ($messages === [])
        {{-- Empty conversation: the composer sits in the middle, like a fresh chat --}}
        <div class="aurora" aria-hidden="true"><span></span><span></span><span></span></div>

        {{-- my-auto, not justify-center: content taller than the screen then scrolls instead of being cut off at the top. --}}
        <div class="flex flex-1 flex-col items-center overflow-y-auto px-4 pb-[12vh]">
            <div class="my-auto w-full max-w-3xl">
                <div class="mb-8 text-center">
                    <div class="mx-auto mb-5 w-fit animate-float">
                        <span class="brand-mark size-14 animate-pop rounded-2xl">
                            <x-icon name="sparkle" class="size-7" />
                        </span>
                    </div>
                    <h1 class="animate-rise text-2xl font-semibold tracking-tight sm:text-3xl">{{ $tool->name }}</h1>
                    <p class="mt-2 animate-rise text-zinc-500 [animation-delay:60ms]">{{ $tool->description }}</p>
                </div>

                <div class="animate-rise [animation-delay:120ms]">
                    @if ($signInRequired)
                        @include('livewire.partials.sign-in-card')
                    @endif

                    @include('livewire.partials.composer')

                    @if ($guestRunsLeft !== null)
                        @include('livewire.partials.guest-hint')
                    @endif
                </div>

                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    @foreach ($starters as $index => $starter)
                        <button
                            type="button"
                            wire:click="useStarter({{ $index }})"
                            style="animation-delay: {{ 180 + $index * 50 }}ms"
                            class="group/starter flex animate-rise items-center gap-1.5 rounded-full border border-zinc-900/8 bg-white px-3.5 py-1.5 text-sm text-zinc-600 shadow-xs transition hover:-translate-y-0.5 hover:border-sun hover:text-zinc-900 hover:shadow-md hover:shadow-sun/25 active:scale-95 dark:border-white/8 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:border-sun/60 dark:hover:text-zinc-100"
                        >
                            <x-icon name="sparkle" class="size-3.5 text-sun transition group-hover/starter:rotate-12 group-hover/starter:scale-125" />
                            {{ $starter['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        {{-- Conversation --}}
        <div x-data="thread" x-on:scroll="track" class="min-h-0 flex-1 overflow-y-auto">
            <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 px-4 pt-6 pb-10">
                @foreach ($messages as $message)
                    @if ($message['role'] === 'user')
                        <div wire:key="message-{{ $message['id'] }}" class="flex animate-slide-in flex-col items-end gap-1.5">
                            {{--
                                Tap a sent message for its actions, like a long-press menu.
                                While open, this lifts above the messages after it, which
                                would otherwise paint over the menu and swallow the tap.
                            --}}
                            <div
                                x-data="{ open: false, copied: false }"
                                x-on:keydown.escape.stop="open = false"
                                :class="open && 'z-20'"
                                class="relative flex max-w-[85%] flex-col items-end"
                            >
                                <button
                                    type="button"
                                    x-on:click="open = ! open"
                                    x-on:contextmenu.prevent="open = true"
                                    :aria-expanded="open"
                                    dir="auto"
                                    data-message-bubble
                                    class="rounded-3xl rounded-br-lg bg-brand/8 px-5 py-3 text-start text-[15px] leading-7 break-words whitespace-pre-wrap ring-1 ring-brand/10 transition hover:bg-brand/12 active:scale-[0.98] dark:bg-brand/15 dark:ring-brand/20 dark:hover:bg-brand/20"
                                >{{ $message['content'] }}</button>

                                <div
                                    x-cloak
                                    x-show="open"
                                    x-on:click.outside="open = false"
                                    x-transition.origin.top.right
                                    class="menu top-full right-0 mt-1.5 w-44"
                                >
                                    <button
                                        type="button"
                                        x-on:click="$copy(@js($message['content'])); copied = true; setTimeout(() => { copied = false; open = false }, 900)"
                                        class="menu-item"
                                    >
                                        <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                                        <x-icon name="copy" class="size-4 opacity-60" />
                                    </button>
                                    <button
                                        type="button"
                                        x-on:click="open = false; $wire.editMessage(@js($message['id']))"
                                        class="menu-item"
                                    >
                                        <span>{{ __('Edit') }}</span>
                                        <x-icon name="pencil-square" class="size-4 opacity-60" />
                                    </button>
                                </div>
                            </div>

                            @if (! empty($message['tags']))
                                <div class="flex flex-wrap justify-end gap-1.5 px-1">
                                    @foreach ($message['tags'] as $tag)
                                        <span class="rounded-full bg-brand/8 px-2 py-0.5 text-xs text-brand dark:bg-brand/15">{{ $tag }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <div wire:key="message-{{ $message['id'] }}" class="group/reply flex animate-rise gap-4">
                            {{-- The avatar and the first line share one 32px row, so they always line up --}}
                            <span @class([
                                'brand-mark size-8 rounded-full',
                                'thinking-orb' => $message['status'] === 'pending',
                            ])>
                                <x-icon name="sparkle" class="size-4" />
                            </span>

                            <div class="min-w-0 flex-1">
                                @if ($message['status'] === 'pending')
                                    {{--
                                        Status until the answer starts: the model's latest thought
                                        when it shares one, "Thinking…" otherwise. Hidden by CSS the
                                        moment the first answer fragment lands below it.
                                    --}}
                                    <div class="flex h-8 min-w-0 items-center group-has-[.stream-target:not(:empty)]/reply:hidden">
                                        <span
                                            wire:stream.replace="status-{{ $message['id'] }}"
                                            dir="auto"
                                            class="shimmer-text truncate text-[15px]"
                                        >{{ __('Thinking…') }}</span>
                                    </div>

                                    {{-- Answer fragments are streamed into this element as they arrive --}}
                                    <div
                                        wire:stream="reply-{{ $message['id'] }}"
                                        dir="auto"
                                        class="stream-target pt-0.5 text-[15px] leading-7 break-words whitespace-pre-wrap"
                                    ></div>
                                @else
                                    @if ($message['content'] !== '')
                                        <div dir="auto" class="prose-chat pt-0.5">
                                            {!! Str::markdown($message['content'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                                        </div>
                                    @endif

                                    @if ($message['status'] === 'failed')
                                        <div class="mt-1 flex animate-pop items-start gap-2.5 rounded-2xl border border-red-200/80 bg-red-50/80 px-4 py-3 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">
                                            <x-icon name="alert" class="mt-px size-5" />
                                            <p>{{ $message['error'] ?? __('Something went wrong.') }}</p>
                                        </div>
                                    @else
                                        <div class="mt-2 flex items-center gap-1 text-zinc-500">
                                            <button
                                                type="button"
                                                x-data="{ copied: false }"
                                                x-on:click="$copy(@js($message['content'])); copied = true; setTimeout(() => copied = false, 1500)"
                                                class="icon-button size-8"
                                                title="{{ __('Copy') }}"
                                                aria-label="{{ __('Copy') }}"
                                            >
                                                <x-icon name="copy" class="size-4" x-show="! copied" />
                                                <x-icon name="check" class="size-4 animate-pop text-brand" x-cloak x-show="copied" />
                                            </button>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="shrink-0 px-4 pb-3">
            <div class="mx-auto w-full max-w-3xl">
                @if ($signInRequired)
                    @include('livewire.partials.sign-in-card')
                @endif

                @include('livewire.partials.composer')

                @if ($guestRunsLeft !== null)
                    @include('livewire.partials.guest-hint')
                @else
                    <p class="mt-2 text-center text-xs text-zinc-500">{{ __('AI can make mistakes. Check important details.') }}</p>
                @endif
            </div>
        </div>
    @endif

    {{--
        At the root, not inside the message box: anything animated around the
        box would otherwise become the sheet's frame and trap it there.
    --}}
    @include('livewire.partials.settings-panel')
</div>
