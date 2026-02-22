<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\OrchestratorServerPoolResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\OrchestratorServerPoolResource;

class EditOrchestratorServerPool extends EditRecord
{
    protected static string $resource = OrchestratorServerPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
