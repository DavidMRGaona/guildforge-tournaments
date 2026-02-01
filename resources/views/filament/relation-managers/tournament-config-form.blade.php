<div>
    <form wire:submit="save">
        {{ $this->configForm }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit">
                {{ __('tournaments::messages.config.save') }}
            </x-filament::button>
        </div>
    </form>
</div>
