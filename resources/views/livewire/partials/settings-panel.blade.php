{{--
    Every setting the tool has, opened from the "⋯" pill. A bottom sheet on
    phones, a centred dialog on wider screens. Each group binds to the same
    Livewire property as its pill, so a change here shows up there too.
--}}
<div
    x-data="{ open: false }"
    x-on:open-settings.window="open = true"
    x-on:keydown.escape.window="open = false"
    x-cloak
    x-show="open"
    class="fixed inset-0 z-50 flex items-end justify-center sm:items-center"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('Settings') }}"
>
    <div x-show="open" x-transition.opacity x-on:click="open = false" class="absolute inset-0 bg-zinc-950/40 backdrop-blur-sm"></div>

    <div
        x-show="open"
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-y-6 opacity-0 sm:translate-y-2"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-end="translate-y-6 opacity-0 sm:translate-y-2"
        class="relative flex max-h-[85dvh] w-full flex-col rounded-t-3xl bg-white shadow-2xl sm:max-w-lg sm:rounded-3xl dark:bg-zinc-900"
    >
        <div class="flex items-center justify-between px-5 pt-4 pb-2">
            <h3 class="text-base font-semibold">{{ __('Settings') }}</h3>
            <button type="button" x-on:click="open = false" class="icon-button" aria-label="{{ __('Close') }}">
                <x-icon name="x-mark" class="size-5" />
            </button>
        </div>

        <div class="flex flex-col gap-6 overflow-y-auto px-5 pt-2 pb-6">
            @if ($models->count() > 1)
                <section
                    x-data="{ value: $wire.entangle('model'), fallback: @js($defaultModel->key), get current() { return this.value ?? this.fallback } }"
                >
                    <p class="mb-2 text-sm font-medium text-zinc-500">{{ __('Model') }}</p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($models as $option)
                            <button
                                type="button"
                                x-on:click="value = @js($option->key)"
                                :class="current === {{ Js::from($option->key) }}
                                    ? 'border-zinc-900 bg-zinc-50 dark:border-zinc-100 dark:bg-zinc-800'
                                    : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700'"
                                class="flex flex-col items-start rounded-2xl border px-3.5 py-2.5 text-start transition"
                            >
                                <span class="text-sm font-medium">{{ $option->name }}</span>
                                <span class="text-xs text-zinc-500">
                                    {{ $option->provider->name }}@if ($loop->first) · {{ __('Default') }}@endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif

            @foreach ($settingFields as $field)
                <section
                    wire:key="panel-{{ $field->name }}"
                    x-data="{ value: $wire.entangle('options.{{ $field->name }}') }"
                >
                    <p class="mb-2 text-sm font-medium text-zinc-500">{{ $field->getLabel() }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($field->getOptions() as $value => $label)
                            <button
                                type="button"
                                x-on:click="value = @js($value)"
                                :class="value === {{ Js::from($value) }}
                                    ? 'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900'
                                    : 'border-zinc-200 text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:text-zinc-300'"
                                class="rounded-full border px-3.5 py-1.5 text-sm transition"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <div class="border-t border-zinc-100 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-zinc-800">
            <button
                type="button"
                x-on:click="open = false"
                class="w-full rounded-full bg-zinc-900 py-2.5 text-sm font-medium text-white transition hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
            >
                {{ __('Done') }}
            </button>
        </div>
    </div>
</div>
