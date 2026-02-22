<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\PendingOrchestratorServiceResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\PendingOrchestratorServiceResource;

class ListPendingOrchestratorServices extends ListRecords
{
    protected static string $resource = PendingOrchestratorServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
