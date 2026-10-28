<x-layouts.app :title="$tool->name" :description="$tool->description" :tools="$tools" :current="$tool->slug">
    @livewire($workspace, ['slug' => $tool->slug], key($tool->slug))
</x-layouts.app>
