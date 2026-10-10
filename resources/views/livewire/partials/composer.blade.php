{{--
    The message box. The tool's primary field is what the user types; every
    other field becomes a pill underneath, like a model or mode switcher.
--}}
<form
    wire:submit="send"
    x-data="composer({{ Js::from($primaryField->maxLength()) }})"
    x-on:focus-composer.window="focus()"
    class="composer"
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
        class="block max-h-60 w-full resize-none bg-transparent px-3 pt-2 pb-1 text-[15px] leading-7 placeholder:text-zinc-400 focus:outline-none dark:placeholder:text-zinc-500"
    ></textarea>

    {{--
        One row under the message box, left to right: the model, then the
        tool's settings in schema order (its most important first), then "⋯"
        for everything. Two settings fit beside the model on wide screens,
        one on a phone; the rest live only in the "⋯" panel.

        Every wrapper here may shrink (min-w-0) and caps its share of the row,
        and every label truncates — so long names end in "…" instead of
        spilling over the next pill.
    --}}
    <div class="flex items-center gap-1.5 px-1 pt-1">
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            @if ($defaultModel)
                <div
                    x-data="{
                        open: false,
                        value: $wire.entangle('model'),
                        fallback: @js($defaultModel->key),
                        names: @js($models->pluck('name', 'key')),
                        get current() { return this.value ?? this.fallback },
                    }"
                    x-on:keydown.escape.stop="open = false"
                    class="relative flex min-w-0 max-w-[48%] sm:max-w-52"
                >
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        :aria-expanded="open"
                        @disabled($models->count() < 2)
                        class="pill disabled:cursor-default"
                        title="{{ __('Model') }}"
                    >
                        <x-icon name="bolt" class="size-3.5 text-accent-1" />
                        <span class="min-w-0 truncate" x-text="names[current] ?? current">{{ $defaultModel->name }}</span>
                        @if ($models->count() > 1)
                            <x-icon name="chevron-down" class="size-3.5 opacity-60" />
                        @endif
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        x-on:click.outside="open = false"
                        x-transition.origin.bottom.left
                        class="menu bottom-full left-0 mb-2 max-h-80 w-72 overflow-y-auto"
                    >
                        <p class="px-2.5 pt-1 pb-1.5 text-xs font-medium text-zinc-500">{{ __('Model') }}</p>
                        @foreach ($models as $option)
                            <button type="button" x-on:click="value = @js($option->key); open = false" class="menu-item">
                                <span class="flex min-w-0 flex-col">
                                    <span class="flex items-center gap-2">
                                        <span class="truncate">{{ $option->name }}</span>
                                        @if ($loop->first)
                                            <span class="rounded-full bg-accent-1/10 px-1.5 text-[11px] text-accent-1">{{ __('Default') }}</span>
                                        @endif
                                    </span>
                                    <span class="text-xs text-zinc-500">{{ $option->provider->name }}</span>
                                </span>
                                <x-icon name="check" class="size-4 shrink-0 text-accent-1" x-show="current === {{ Js::from($option->key) }}" />
                            </button>
                        @endforeach
                        <button
                            type="button"
                            x-on:click="open = false; $dispatch('open-settings')"
                            class="menu-item mt-1 border-t border-zinc-900/5 pt-2.5 text-zinc-500 dark:border-white/5"
                        >
                            <span>{{ __('Search all models…') }}</span>
                            <x-icon name="search" class="size-4" />
                        </button>
                    </div>
                </div>
            @endif

            @foreach (array_slice($settingFields, 0, 2) as $index => $field)
                <div
                    wire:key="setting-{{ $field->name }}"
                    x-data="{ open: false, value: $wire.entangle('options.{{ $field->name }}'), labels: @js($field->getOptions()) }"
                    x-on:keydown.escape.stop="open = false"
                    @class(['relative min-w-0 max-w-[38%] sm:max-w-40', 'flex' => $index === 0, 'hidden sm:flex' => $index > 0])
                >
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        :aria-expanded="open"
                        class="pill"
                        title="{{ $field->getLabel() }}"
                    >
                        <span class="min-w-0 truncate" x-text="labels[value] ?? value">{{ $field->labelFor((string) ($options[$field->name] ?? '')) }}</span>
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
                                <x-icon name="check" class="size-4 text-accent-1" x-show="value === {{ Js::from($value) }}" />
                            </button>
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if ($settingFields !== [] || $models->count() > 1)
                <button
                    type="button"
                    x-on:click="$dispatch('open-settings')"
                    class="pill shrink-0 px-2.5"
                    title="{{ __('All settings') }}"
                    aria-label="{{ __('All settings') }}"
                >
                    <x-icon name="dots" class="size-4" />
                </button>
            @endif
        </div>

        <span
            x-cloak
            x-show="nearLimit"
            class="shrink-0 text-xs tabular-nums text-zinc-500"
            x-text="`${count} / ${max}`"
        ></span>

        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="send, respond"
            :disabled="isEmpty"
            class="send-button"
            aria-label="{{ __('Send') }}"
        >
            <x-icon name="arrow-up" class="size-4.5" wire:loading.remove wire:target="send, respond" />
            <span class="size-3 animate-pulse rounded-[3px] bg-current" wire:loading wire:target="send, respond"></span>
        </button>
    </div>
</form>

@error($primaryField->name)
    <p class="mt-2 animate-rise px-4 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
@enderror

@include('livewire.partials.settings-panel')
