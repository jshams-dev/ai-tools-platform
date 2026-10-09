{{--
    The message box. The tool's primary field is what the user types; every
    other field becomes a pill underneath, like a model or mode switcher.
--}}
<form
    wire:submit="send"
    x-data="composer({{ Js::from($primaryField->maxLength()) }})"
    x-on:focus-composer.window="focus()"
    class="rounded-[26px] border border-zinc-200 bg-white p-2 shadow-[0_2px_12px_-4px_rgb(0_0_0/0.08)] transition focus-within:border-zinc-300 focus-within:shadow-[0_4px_24px_-6px_rgb(0_0_0/0.12)] dark:border-zinc-800 dark:bg-zinc-900 dark:focus-within:border-zinc-700"
>
    <label for="composer-input" class="sr-only">{{ $primaryField->getLabel() }}</label>
    <textarea
        id="composer-input"
        x-ref="input"
        wire:model="prompt"
        x-on:input="resize()"
        x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); submit() }"
        rows="1"
        dir="auto"
        @if ($primaryField->maxLength()) maxlength="{{ $primaryField->maxLength() }}" @endif
        placeholder="{{ $primaryField->getPlaceholder() ?? __('Message') }}"
        class="block max-h-60 w-full resize-none bg-transparent px-3 pt-2 pb-1 text-[15px] leading-7 placeholder:text-zinc-400 focus:outline-none"
    ></textarea>

    <div class="flex items-center gap-1.5 px-1 pt-1">
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
            @foreach ($settingFields as $field)
                <div
                    wire:key="setting-{{ $field->name }}"
                    x-data="{ open: false, value: $wire.entangle('options.{{ $field->name }}'), labels: @js($field->getOptions()) }"
                    x-on:keydown.escape.stop="open = false"
                    class="relative"
                >
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        :aria-expanded="open"
                        class="pill"
                        title="{{ $field->getLabel() }}"
                    >
                        <span x-text="labels[value] ?? value">{{ $field->labelFor((string) ($options[$field->name] ?? '')) }}</span>
                        <x-icon name="chevron-down" class="size-3.5 opacity-60" />
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        x-on:click.outside="open = false"
                        x-transition.origin.bottom.left
                        class="menu bottom-full left-0 mb-2"
                    >
                        <p class="px-2.5 pt-1 pb-1.5 text-xs font-medium text-zinc-500">{{ $field->getLabel() }}</p>
                        @foreach ($field->getOptions() as $value => $label)
                            <button
                                type="button"
                                x-on:click="value = @js($value); open = false"
                                class="menu-item"
                            >
                                <span>{{ $label }}</span>
                                <x-icon name="check" class="size-4" x-show="value === {{ Js::from($value) }}" />
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        @if ($models->count() > 1)
            {{-- The tool's models from the database; the first is its default --}}
            <div
                x-data="{
                    open: false,
                    value: $wire.entangle('model'),
                    fallback: @js($defaultModel->key),
                    names: @js($models->pluck('name', 'key')),
                    get current() { return this.value ?? this.fallback },
                }"
                x-on:keydown.escape.stop="open = false"
                class="relative"
            >
                <button type="button" x-on:click="open = ! open" class="pill max-w-44" title="{{ __('Model') }}">
                    <x-icon name="bolt" class="size-3.5 opacity-60" />
                    <span class="truncate" x-text="names[current] ?? current">{{ $defaultModel->name }}</span>
                    <x-icon name="chevron-down" class="size-3.5 opacity-60" />
                </button>

                <div
                    x-cloak
                    x-show="open"
                    x-on:click.outside="open = false"
                    x-transition.origin.bottom.right
                    class="menu right-0 bottom-full mb-2 max-h-80 w-72 overflow-y-auto"
                >
                    <p class="px-2.5 pt-1 pb-1.5 text-xs font-medium text-zinc-500">{{ __('Model') }}</p>
                    @foreach ($models as $option)
                        <button type="button" x-on:click="value = @js($option->key); open = false" class="menu-item">
                            <span class="flex min-w-0 flex-col">
                                <span class="flex items-center gap-2">
                                    <span class="truncate">{{ $option->name }}</span>
                                    @if ($loop->first)
                                        <span class="rounded-full bg-zinc-100 px-1.5 text-[11px] text-zinc-500 dark:bg-zinc-800">{{ __('Default') }}</span>
                                    @endif
                                </span>
                                <span class="text-xs text-zinc-500">{{ $option->provider->name }}</span>
                            </span>
                            <x-icon name="check" class="size-4" x-show="current === {{ Js::from($option->key) }}" />
                        </button>
                    @endforeach
                </div>
            </div>
        @elseif ($defaultModel)
            <span class="hidden items-center gap-1 px-2 text-xs text-zinc-500 sm:inline-flex" title="{{ __('Model') }}">
                <x-icon name="bolt" class="size-3.5" />
                {{ $defaultModel->name }}
            </span>
        @endif

        <span
            x-cloak
            x-show="nearLimit"
            class="text-xs tabular-nums text-zinc-500"
            x-text="`${count} / ${max}`"
        ></span>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="send, respond"
            :disabled="isEmpty"
            class="grid size-9 shrink-0 place-items-center rounded-full bg-zinc-900 text-white transition hover:bg-zinc-700 disabled:cursor-not-allowed disabled:bg-zinc-200 disabled:text-zinc-400 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 dark:disabled:bg-zinc-800 dark:disabled:text-zinc-600"
            aria-label="{{ __('Send') }}"
        >
            <x-icon name="arrow-up" class="size-4.5" wire:loading.remove wire:target="send, respond" />
            <span class="size-3 animate-pulse rounded-[3px] bg-current" wire:loading wire:target="send, respond"></span>
        </button>
    </div>
</form>

@error($primaryField->name)
    <p class="mt-2 px-4 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
@enderror
