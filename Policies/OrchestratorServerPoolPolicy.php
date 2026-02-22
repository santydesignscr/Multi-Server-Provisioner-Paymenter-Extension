<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Policies;

use App\Models\User;
use App\Policies\BasePolicy;
use Paymenter\Extensions\Servers\Orchestrator\Models\OrchestratorServerPool;

class OrchestratorServerPoolPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.orchestrator.view')
            || $user->hasPermission('admin.servers.viewAny')
            || $user->hasPermission('admin.services.viewAny');
    }

    public function view(User $user, OrchestratorServerPool $pool): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('admin.orchestrator.create')
            || $user->hasPermission('admin.servers.create')
            || $user->hasPermission('admin.services.update');
    }

    public function update(User $user, OrchestratorServerPool $pool): bool
    {
        return $user->hasPermission('admin.orchestrator.update')
            || $user->hasPermission('admin.servers.update')
            || $user->hasPermission('admin.services.update');
    }

    public function delete(User $user, OrchestratorServerPool $pool): bool
    {
        return $user->hasPermission('admin.orchestrator.delete')
            || $user->hasPermission('admin.servers.delete')
            || $user->hasPermission('admin.services.update');
    }

    public function deleteAny(User $user): bool
    {
        return $this->delete($user, new OrchestratorServerPool);
    }
}
