<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\OrchestratorServerPoolResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\OrchestratorServerPoolResource;

class ListOrchestratorServerPools extends ListRecords
{
    protected static string $resource = OrchestratorServerPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
