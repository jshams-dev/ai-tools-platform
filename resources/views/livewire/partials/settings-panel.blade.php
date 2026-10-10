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
    {{-- Tapping outside the sheet closes it; "Done" is not required. --}}
    <div x-show="open" x-transition.opacity x-on:click="open = false" class="absolute inset-0 bg-zinc-950/45"></div>

    <div
        x-show="open"
        x-transition:enter="transition duration-200 ease-out"
        x-transition:enter-start="translate-y-6 opacity-0 sm:translate-y-2"
        x-transition:leave="transition duration-150 ease-in"
        x-transition:leave-end="translate-y-6 opacity-0 sm:translate-y-2"
        class="surface relative flex max-h-[85dvh] w-full flex-col rounded-t-3xl border-b-0 sm:max-w-lg sm:rounded-3xl sm:border-b"
    >
        {{-- Grab handle: says "this is a sheet you can dismiss" on phones --}}
        <div class="mx-auto mt-2.5 h-1 w-10 rounded-full bg-zinc-900/15 sm:hidden dark:bg-white/20"></div>
        <div class="flex items-center justify-between px-5 pt-4 pb-2">
            <h3 class="text-base font-semibold">{{ __('Settings') }}</h3>
            <button type="button" x-on:click="open = false" class="icon-button" aria-label="{{ __('Close') }}">
                <x-icon name="x-mark" class="size-5" />
            </button>
        </div>

        <div class="flex flex-col gap-6 overflow-y-auto px-5 pt-2 pb-6">
            @if ($models->count() > 1)
                {{--
                    Searchable, because the model list is expected to grow to
                    dozens. Filtering is client-side: the list is already on
                    the page, so a keystroke needs no round trip.
                --}}
                <section
                    x-data="modelPicker({
                        value: $wire.entangle('model'),
                        models: @js($models->map(fn ($m, $i) => [
                            'key' => $m->key,
                            'name' => $m->name,
                            'provider' => $m->provider->name,
                            'isDefault' => $i === 0,
                        ])->values()),
                        fallback: @js($defaultModel->key),
                    })"
                >
                    <p class="mb-2 text-sm font-medium text-zinc-500">{{ __('Model') }}</p>

                    <label class="relative block">
                        <span class="sr-only">{{ __('Search models') }}</span>
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-zinc-400" />
                        <input
                            type="search"
                            x-model="query"
                            placeholder="{{ __('Search models…') }}"
                            class="h-10 w-full rounded-xl border border-zinc-900/8 bg-zinc-900/3 ps-10 pe-3 text-sm outline-none transition placeholder:text-zinc-400 focus:border-brand/40 focus:ring-4 focus:ring-brand/10 dark:border-white/8 dark:bg-white/5"
                        >
                    </label>

                    <div class="mt-2.5 flex flex-wrap gap-1.5">
                        <template x-for="name in ['', ...providers]" :key="name">
                            <button
                                type="button"
                                x-on:click="provider = name"
                                x-text="name === '' ? @js(__('All')) : name"
                                :class="provider === name
                                    ? 'border-brand/40 bg-brand/10 text-brand'
                                    : 'border-zinc-900/8 text-zinc-600 hover:border-zinc-900/15 dark:border-white/8 dark:text-zinc-400'"
                                class="rounded-full border px-3 py-1 text-xs font-medium transition"
                            ></button>
                        </template>
                    </div>

                    <div class="mt-3 grid max-h-64 gap-2 overflow-y-auto pe-1 sm:grid-cols-2">
                        <template x-for="model in filtered" :key="model.key">
                            <button
                                type="button"
                                x-on:click="value = model.key"
                                :class="current === model.key
                                    ? 'border-brand/50 bg-brand/8 ring-4 ring-brand/8'
                                    : 'border-zinc-900/8 hover:border-zinc-900/15 dark:border-white/8 dark:hover:border-white/15'"
                                class="flex flex-col items-start rounded-2xl border px-3.5 py-2.5 text-start transition"
                            >
                                <span class="text-sm font-medium" x-text="model.name"></span>
                                <span class="text-xs text-zinc-500" x-text="model.provider + (model.isDefault ? ' · ' + @js(__('Default')) : '')"></span>
                            </button>
                        </template>

                        <p x-show="filtered.length === 0" class="py-6 text-center text-sm text-zinc-500 sm:col-span-2">
                            {{ __('No models match.') }}
                        </p>
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
                                    ? 'border-brand/50 bg-brand/10 text-brand'
                                    : 'border-zinc-900/8 text-zinc-700 hover:border-zinc-900/15 dark:border-white/8 dark:text-zinc-300 dark:hover:border-white/15'"
                                class="rounded-full border px-3.5 py-1.5 text-sm transition active:scale-95"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <div class="border-t border-zinc-900/5 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-white/5">
            <button
                type="button"
                x-on:click="open = false"
                class="w-full rounded-full bg-brand-gradient py-2.5 text-sm font-medium text-white shadow-md shadow-brand/25 transition hover:shadow-lg active:scale-[0.98]"
            >
                {{ __('Done') }}
            </button>
        </div>
    </div>
</div>
