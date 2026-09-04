<?php

namespace App\Filament\Resources\Words\Pages;

use App\Filament\Resources\Words\WordResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWord extends EditRecord
{
    protected static string $resource = WordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Oʼchirish'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return WordResource::applyIcon($data, $this->getRecord()->icon_id);
    }

    protected function getRedirectUrl(): ?string
    {
        return null;   // stay on the word after saving
    }
}
