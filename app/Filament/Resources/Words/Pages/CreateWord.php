<?php

namespace App\Filament\Resources\Words\Pages;

use App\Filament\Resources\Words\WordResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWord extends CreateRecord
{
    protected static string $resource = WordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source'] = 'manual';
        $data['translation_status'] = filled($data['translations']['uz'] ?? null) ? 'done' : 'pending';

        return WordResource::applyIcon($data, null);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
