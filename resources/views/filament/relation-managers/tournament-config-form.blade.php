<div>
    <form wire:submit="save" class="fi-sc-form">
        {{ $this->configForm }}

        <x-filament::actions alignment="end">
            <x-filament::button type="submit">
                {{ __('tournaments::messages.config.save') }}
            </x-filament::button>
        </x-filament::actions>
    </form>
</div>
