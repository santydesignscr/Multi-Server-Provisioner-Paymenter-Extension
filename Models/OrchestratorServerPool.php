<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrchestratorServerPool extends Model
{
    protected $table = 'ext_orchestrator_server_pools';

    protected $fillable = [
        'orchestrator_server_id',
        'target_server_id',
        'total_slots',
        'maintenance',
    ];

    protected $casts = [
        'maintenance' => 'boolean',
    ];

    public function orchestratorServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'orchestrator_server_id');
    }

    public function targetServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_server_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(OrchestratorServiceAllocation::class, 'orchestrator_server_pool_id');
    }
}
