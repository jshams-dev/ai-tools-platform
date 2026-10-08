<x-layouts.app :title="$tool->name()" :description="$tool->description()" :tools="$tools" :current="$tool->slug()">
    @livewire($tool->component() ?? 'tool-workspace', ['slug' => $tool->slug()], key($tool->slug()))
</x-layouts.app>
