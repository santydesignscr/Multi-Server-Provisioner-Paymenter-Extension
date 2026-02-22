<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Models;

use App\Models\Service;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrchestratorServiceAllocation extends Model
{
    protected $table = 'ext_orchestrator_service_allocations';

    protected $fillable = [
        'orchestrator_server_pool_id',
        'service_id',
        'target_server_id',
        'slots',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(OrchestratorServerPool::class, 'orchestrator_server_pool_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }
}
