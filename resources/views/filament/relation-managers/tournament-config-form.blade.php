<div>
    <form wire:submit="save" class="fi-sc-form">
        {{ $this->configForm }}

        @if ($this->canSaveTournament())
            <x-filament::actions alignment="end">
                <x-filament::button type="submit">
                    {{ __('tournaments::messages.config.save') }}
                </x-filament::button>
            </x-filament::actions>
        @endif
    </form>

    <x-filament-actions::modals />
</div>
