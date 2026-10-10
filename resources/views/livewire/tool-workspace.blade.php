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

        <div class="flex flex-1 flex-col items-center justify-center overflow-y-auto px-4 pb-[12vh]">
            <div class="w-full max-w-3xl">
                <div class="mb-8 text-center">
                    <span class="brand-mark mx-auto mb-5 size-12 animate-pop rounded-2xl">
                        <x-icon name="sparkle" class="size-6" />
                    </span>
                    <h1 class="animate-rise text-2xl font-semibold tracking-tight sm:text-3xl">{{ $tool->name }}</h1>
                    <p class="mt-2 animate-rise text-zinc-500 [animation-delay:60ms]">{{ $tool->description }}</p>
                </div>

                <div class="animate-rise [animation-delay:120ms]">
                    @include('livewire.partials.composer')
                </div>

                <div class="mt-5 flex flex-wrap justify-center gap-2">
                    @foreach ($starters as $index => $starter)
                        <button
                            type="button"
                            wire:click="useStarter({{ $index }})"
                            style="animation-delay: {{ 180 + $index * 50 }}ms"
                            class="animate-rise rounded-full border border-zinc-900/8 bg-white/60 px-3.5 py-1.5 text-sm text-zinc-600 backdrop-blur transition hover:-translate-y-0.5 hover:border-accent-1/30 hover:text-zinc-900 hover:shadow-md hover:shadow-accent-1/10 dark:border-white/8 dark:bg-white/5 dark:text-zinc-400 dark:hover:text-zinc-100"
                        >
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
                        <div wire:key="message-{{ $message['id'] }}" class="flex animate-rise flex-col items-end gap-1.5">
                            {{-- Tap a sent message for its actions, like a long-press menu --}}
                            <div
                                x-data="{ open: false, copied: false }"
                                x-on:keydown.escape.stop="open = false"
                                class="relative flex max-w-[85%] flex-col items-end"
                            >
                                <button
                                    type="button"
                                    x-on:click="open = ! open"
                                    x-on:contextmenu.prevent="open = true"
                                    :aria-expanded="open"
                                    dir="auto"
                                    class="rounded-3xl rounded-br-lg bg-zinc-900/5 px-5 py-3 text-start text-[15px] leading-7 break-words whitespace-pre-wrap transition hover:bg-zinc-900/8 active:scale-[0.99] dark:bg-white/8 dark:hover:bg-white/12"
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
                                        <span class="rounded-full bg-accent-1/8 px-2 py-0.5 text-xs text-accent-1 dark:bg-accent-1/15">{{ $tag }}</span>
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
                                                <x-icon name="check" class="size-4" x-cloak x-show="copied" />
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
                @include('livewire.partials.composer')

                <p class="mt-2 text-center text-xs text-zinc-500">{{ __('AI can make mistakes. Check important details.') }}</p>
            </div>
        </div>
    @endif
</div>
