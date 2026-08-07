<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex gap-3">
            <x-filament::button type="submit">
                Сохранить
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="sendTest" wire:loading.attr="disabled">
                Сохранить и отправить тестовое сообщение
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="connectBot" wire:loading.attr="disabled">
                Подключить бота (команда /id)
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
