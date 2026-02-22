<?php

namespace Paymenter\Extensions\Servers\Orchestrator;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Service;
use Exception;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Paymenter\Extensions\Servers\Orchestrator\Models\OrchestratorServiceAllocation;
use Paymenter\Extensions\Servers\Orchestrator\Models\OrchestratorServerPool;
use Paymenter\Extensions\Servers\Orchestrator\Policies\OrchestratorServerPoolPolicy;

#[ExtensionMeta(
    name: 'Orchestrator',
    description: 'Orchestrates provisioning across configured server extensions using slot capacity and maintenance flags.',
    version: '0.1.0',
    author: 'Santiago Rodriguez',
    url: '',
    icon: ''
)]
class Orchestrator extends Server
{
    public function getConfig($values = []): array
    {
        return [
            [
                'name' => 'allocation_strategy',
                'label' => 'Allocation Strategy',
                'type' => 'select',
                'required' => true,
                'default' => 'most_free_slots',
                'options' => [
                    'most_free_slots' => 'Most Free Slots',
                    'round_robin' => 'Round Robin (Least Recently Assigned)',
                ],
                'description' => 'Controls how the orchestrator chooses the target server pool when provisioning.',
            ],
        ];
    }

    public function boot(): void
    {
        Gate::policy(OrchestratorServerPool::class, OrchestratorServerPoolPolicy::class);

        Event::listen('permissions', function () {
            return [
                'admin.orchestrator.view' => 'View Orchestrator Pools',
                'admin.orchestrator.create' => 'Create Orchestrator Pools',
                'admin.orchestrator.update' => 'Update Orchestrator Pools',
                'admin.orchestrator.delete' => 'Delete Orchestrator Pools',
                'admin.orchestrator.deploy' => 'Deploy Pending Orchestrator Services',
            ];
        });
    }

    public function installed(): void
    {
        ExtensionHelper::runMigrations('extensions/Servers/Orchestrator/database/migrations');
    }

    public function uninstalled(): void
    {
        ExtensionHelper::rollbackMigrations('extensions/Servers/Orchestrator/database/migrations');
    }

    public function upgraded($oldVersion = null): void
    {
        ExtensionHelper::runMigrations('extensions/Servers/Orchestrator/database/migrations');
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'required_slots',
                'label' => 'Required Slots',
                'type' => 'number',
                'required' => true,
                'default' => 1,
                'min_value' => 1,
                'description' => 'How many slots this product consumes from the orchestrator pool.',
            ],
            [
                'name' => 'required_storage_mb',
                'label' => 'Required Storage (MB)',
                'type' => 'number',
                'required' => false,
                'default' => 0,
                'min_value' => 0,
                'description' => 'Optional. Storage to reserve from the pool for this product.',
            ],
            [
                'name' => 'required_bandwidth_mbps',
                'label' => 'Required Bandwidth (Mbps)',
                'type' => 'number',
                'required' => false,
                'default' => 0,
                'min_value' => 0,
                'description' => 'Optional. Bandwidth to reserve from the pool for this product.',
            ],
            [
                'name' => 'target_plan',
                'label' => 'Target Plan / Package Name',
                'type' => 'text',
                'required' => true,
                'description' => 'Required. Plan name sent to target extension as plan/package if not already defined.',
            ],
        ];
    }

    public function getCheckoutConfig(Product $product, $values = [], $settings = []): array
    {
        return [
            [
                'name' => 'domain',
                'label' => 'Domain',
                'type' => 'text',
                'required' => true,
                'description' => 'Domain required for provisioning in the target server.',
            ],
        ];
    }

    public function createServer(Service $service, $settings, $properties)
    {
        $this->syncOrchestratorProperties($service, [
            'orchestrator_provisioning_state' => 'creating',
        ]);
        $service->properties()->where('key', 'orchestrator_pending_reason')->delete();

        if ($service->status !== Service::STATUS_PENDING) {
            $service->status = Service::STATUS_PENDING;
            $service->save();
        }

        $requirements = $this->resolveRequirements($service, $settings, $properties);

        $allocation = OrchestratorServiceAllocation::with('pool.targetServer')
            ->where('service_id', $service->id)
            ->first();

        $createdAllocation = false;

        if (!$allocation) {
            $allocation = $this->allocatePool((int) $service->product->server_id, $requirements, (int) $service->id);
            $createdAllocation = $allocation !== null;
        }

        if (!$allocation) {
            $service->status = Service::STATUS_PENDING;
            $service->save();

            $this->syncOrchestratorProperties($service, [
                'orchestrator_provisioning_state' => 'pending',
                'orchestrator_pending_reason' => 'no_capacity',
            ]);

            throw new Exception('No capacity available in orchestrator pools. Service left in pending state.');
        }

        $pool = $allocation->pool;

        if (!$pool) {
            throw new Exception('No pool found for the orchestrator allocation.');
        }

        if (!$this->poolHasCapacity($pool, $requirements, $allocation->id)) {
            if ($createdAllocation) {
                $allocation->delete();
            }

            $service->status = Service::STATUS_PENDING;
            $service->save();

            $this->syncOrchestratorProperties($service, [
                'orchestrator_provisioning_state' => 'pending',
                'orchestrator_pending_reason' => 'no_capacity',
            ]);

            throw new Exception('No capacity available in orchestrator pools for the requested storage/bandwidth.');
        }

        $allocation->update([
            'slots' => $requirements['slots'],
            'storage_mb' => $requirements['storage_mb'],
            'bandwidth_mbps' => $requirements['bandwidth_mbps'],
        ]);

        try {
            $result = $this->callTargetAction($allocation, 'createServer', $service, $settings, $properties);

            $this->syncOrchestratorProperties($service, [
                'orchestrator_target_server_id' => (string) $allocation->target_server_id,
                'orchestrator_required_slots' => (string) $allocation->slots,
                'orchestrator_required_storage_mb' => (string) $allocation->storage_mb,
                'orchestrator_required_bandwidth_mbps' => (string) $allocation->bandwidth_mbps,
                'orchestrator_server_pool_id' => (string) $allocation->orchestrator_server_pool_id,
                'orchestrator_provisioning_state' => 'active',
            ]);
            $service->properties()->where('key', 'orchestrator_pending_reason')->delete();

            if ($service->status !== Service::STATUS_ACTIVE) {
                $service->status = Service::STATUS_ACTIVE;
                $service->save();
            }

            return array_merge(is_array($result) ? $result : [], [
                'orchestrator_target_server_id' => $allocation->target_server_id,
                'orchestrator_required_slots' => $allocation->slots,
                'orchestrator_required_storage_mb' => $allocation->storage_mb,
                'orchestrator_required_bandwidth_mbps' => $allocation->bandwidth_mbps,
            ]);
        } catch (Exception $e) {
            $service->status = Service::STATUS_PENDING;
            $service->save();

            $this->syncOrchestratorProperties($service, [
                'orchestrator_provisioning_state' => 'pending',
                'orchestrator_pending_reason' => 'error',
            ]);

            if ($createdAllocation) {
                $allocation->delete();
            }

            throw $e;
        }
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $allocation = $this->getAllocationOrFail($service);

        return $this->callTargetAction($allocation, 'suspendServer', $service, $settings, $properties);
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $allocation = $this->getAllocationOrFail($service);

        return $this->callTargetAction($allocation, 'unsuspendServer', $service, $settings, $properties);
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $allocation = $this->getAllocationOrFail($service);

        $result = $this->callTargetAction($allocation, 'terminateServer', $service, $settings, $properties);

        $allocation->delete();

        $service->properties()->whereIn('key', [
            'orchestrator_target_server_id',
            'orchestrator_required_slots',
            'orchestrator_required_storage_mb',
            'orchestrator_required_bandwidth_mbps',
            'orchestrator_server_pool_id',
            'orchestrator_provisioning_state',
            'orchestrator_pending_reason',
        ])->delete();

        return $result;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $allocation = $this->getAllocationOrFail($service);

        return $this->callTargetAction($allocation, 'upgradeServer', $service, $settings, $properties);
    }

    public function getActions(Service $service, $settings, $properties): array
    {
        $allocation = $this->getAllocationOrFail($service);

        $actions = $this->callTargetAction($allocation, 'getActions', $service, $settings, $properties);

        return is_array($actions) ? $actions : [];
    }

    public function getLoginUrl(Service $service, $settings, $properties)
    {
        $allocation = $this->getAllocationOrFail($service);

        return $this->callTargetAction($allocation, 'getLoginUrl', $service, $settings, $properties);
    }

    public function getControlPanelUrl(Service $service, $settings, $properties)
    {
        $allocation = $this->getAllocationOrFail($service);

        return $this->callTargetAction($allocation, 'getControlPanelUrl', $service, $settings, $properties);
    }

    public function __call(string $method, array $arguments)
    {
        if (empty($arguments) || !($arguments[0] instanceof Service)) {
            throw new Exception('Invalid passthrough invocation for orchestrator method: ' . $method);
        }

        /** @var Service $service */
        $service = $arguments[0];
        $settings = (array) ($arguments[1] ?? []);
        $properties = (array) ($arguments[2] ?? []);
        $extraArgs = array_slice($arguments, 3);

        $allocation = $this->getAllocationOrFail($service);

        return $this->callTargetAction($allocation, $method, $service, $settings, $properties, $extraArgs);
    }

    protected function getAllocationOrFail(Service $service): OrchestratorServiceAllocation
    {
        $allocation = OrchestratorServiceAllocation::with('pool.targetServer')
            ->where('service_id', $service->id)
            ->first();

        if ($allocation) {
            return $allocation;
        }

        $targetServerId = (int) ($service->properties()->where('key', 'orchestrator_target_server_id')->value('value') ?? 0);
        $poolId = (int) ($service->properties()->where('key', 'orchestrator_server_pool_id')->value('value') ?? 0);
        $slots = (int) ($service->properties()->where('key', 'orchestrator_required_slots')->value('value') ?? 0);
        $storageMb = (int) ($service->properties()->where('key', 'orchestrator_required_storage_mb')->value('value') ?? 0);
        $bandwidthMbps = (int) ($service->properties()->where('key', 'orchestrator_required_bandwidth_mbps')->value('value') ?? 0);

        if ($targetServerId > 0) {
            $pool = OrchestratorServerPool::query()
                ->when($poolId > 0, fn ($query) => $query->where('id', $poolId), fn ($query) => $query
                    ->where('orchestrator_server_id', (int) $service->product->server_id)
                    ->where('target_server_id', $targetServerId))
                ->first();

            if ($pool) {
                $allocation = OrchestratorServiceAllocation::firstOrCreate(
                    ['service_id' => $service->id],
                    [
                        'orchestrator_server_pool_id' => $pool->id,
                        'target_server_id' => $pool->target_server_id,
                        'slots' => max(1, $slots),
                        'storage_mb' => max(0, $storageMb),
                        'bandwidth_mbps' => max(0, $bandwidthMbps),
                    ]
                );

                return OrchestratorServiceAllocation::with('pool.targetServer')
                    ->whereKey($allocation->id)
                    ->first();
            }
        }

        $fallbackPool = OrchestratorServerPool::query()
            ->where('orchestrator_server_id', (int) $service->product->server_id)
            ->where('maintenance', false)
            ->orderBy('id')
            ->first();

        if ($fallbackPool) {
            $allocation = OrchestratorServiceAllocation::firstOrCreate(
                ['service_id' => $service->id],
                [
                    'orchestrator_server_pool_id' => $fallbackPool->id,
                    'target_server_id' => $fallbackPool->target_server_id,
                    'slots' => max(1, $slots),
                        'storage_mb' => max(0, $storageMb),
                        'bandwidth_mbps' => max(0, $bandwidthMbps),
                ]
            );

            $this->syncOrchestratorProperties($service, [
                'orchestrator_target_server_id' => (string) $fallbackPool->target_server_id,
                'orchestrator_required_slots' => (string) max(1, $slots),
                'orchestrator_required_storage_mb' => (string) max(0, $storageMb),
                'orchestrator_required_bandwidth_mbps' => (string) max(0, $bandwidthMbps),
                'orchestrator_server_pool_id' => (string) $fallbackPool->id,
            ]);

            return OrchestratorServiceAllocation::with('pool.targetServer')
                ->whereKey($allocation->id)
                ->first();
        }

        throw new Exception('No orchestrator allocation found for this service.');
    }

    protected function poolUsage(OrchestratorServerPool $pool, ?int $ignoreAllocationId = null): array
    {
        $allocations = $pool->allocations()
            ->when($ignoreAllocationId, fn ($query) => $query->where('id', '!=', $ignoreAllocationId));

        return [
            'slots' => (int) $allocations->sum('slots'),
            'storage_mb' => (int) $allocations->sum('storage_mb'),
            'bandwidth_mbps' => (int) $allocations->sum('bandwidth_mbps'),
        ];
    }

    protected function poolHasCapacity(OrchestratorServerPool $pool, array $requirements, ?int $ignoreAllocationId = null): bool
    {
        $usage = $this->poolUsage($pool, $ignoreAllocationId);

        $slotsOk = (int) $pool->total_slots >= $usage['slots'] + $requirements['slots'];
        $storageOk = (int) $pool->total_storage_mb >= $usage['storage_mb'] + $requirements['storage_mb'];
        $bandwidthOk = (int) $pool->total_bandwidth_mbps >= $usage['bandwidth_mbps'] + $requirements['bandwidth_mbps'];

        return $slotsOk && $storageOk && $bandwidthOk;
    }

    protected function allocatePool(int $orchestratorServerId, array $requirements, int $serviceId): ?OrchestratorServiceAllocation
    {
        $pools = OrchestratorServerPool::with('targetServer')
            ->where('orchestrator_server_id', $orchestratorServerId)
            ->where('maintenance', false)
            ->get();

        $candidates = [];

        foreach ($pools as $pool) {
            if (!$pool->targetServer || strtolower($pool->targetServer->extension) === 'orchestrator') {
                continue;
            }

            $usage = $this->poolUsage($pool);

            $availableSlots = (int) $pool->total_slots - $usage['slots'];
            $availableStorage = (int) $pool->total_storage_mb - $usage['storage_mb'];
            $availableBandwidth = (int) $pool->total_bandwidth_mbps - $usage['bandwidth_mbps'];

            $meetsSlots = $availableSlots >= $requirements['slots'];
            $meetsStorage = $availableStorage >= $requirements['storage_mb'];
            $meetsBandwidth = $availableBandwidth >= $requirements['bandwidth_mbps'];

            if (!$meetsSlots || !$meetsStorage || !$meetsBandwidth) {
                continue;
            }

            $usagePercentage = $pool->total_slots > 0
                ? round(($usage['slots'] / (int) $pool->total_slots) * 100, 4)
                : 100.0;

            $candidates[] = [
                'pool' => $pool,
                'used_slots' => $usage['slots'],
                'available_slots' => $availableSlots,
                'available_storage' => $availableStorage,
                'available_bandwidth' => $availableBandwidth,
                'usage_percentage' => $usagePercentage,
                'last_allocated_at' => $pool->allocations()->max('created_at'),
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        $strategy = (string) ($this->config('allocation_strategy') ?? 'most_free_slots');

        usort($candidates, function (array $left, array $right) use ($strategy) {
            if ($strategy === 'round_robin') {
                $leftTs = $left['last_allocated_at'] ? strtotime((string) $left['last_allocated_at']) : 0;
                $rightTs = $right['last_allocated_at'] ? strtotime((string) $right['last_allocated_at']) : 0;

                $cmp = $leftTs <=> $rightTs;
                if ($cmp !== 0) {
                    return $cmp;
                }

                return $right['available_slots'] <=> $left['available_slots'];
            }

            $cmp = $right['available_slots'] <=> $left['available_slots'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return $left['usage_percentage'] <=> $right['usage_percentage'];
        });

        /** @var OrchestratorServerPool $selectedPool */
        $selectedPool = $candidates[0]['pool'];

        return OrchestratorServiceAllocation::create([
            'orchestrator_server_pool_id' => $selectedPool->id,
            'service_id' => $serviceId,
            'target_server_id' => $selectedPool->target_server_id,
            'slots' => $requirements['slots'],
            'storage_mb' => $requirements['storage_mb'],
            'bandwidth_mbps' => $requirements['bandwidth_mbps'],
        ]);

    }

    protected function callTargetAction(OrchestratorServiceAllocation $allocation, string $action, Service $service, array $settings, array $properties, array $extraArgs = [])
    {
        $targetServer = $allocation->pool?->targetServer;

        if (!$targetServer) {
            throw new Exception('Orchestrator target server not found.');
        }

        $targetExtension = ExtensionHelper::getExtension('server', $targetServer->extension, $targetServer->settings);

        if (!method_exists($targetExtension, $action)) {
            if ($action === 'createServer') {
                throw new Exception('Target server does not support provisioning.');
            }

            return null;
        }

        $payload = $this->buildTargetPayload($settings, $properties);

        return $this->invokeTargetAction($targetExtension, $action, $service, $payload['settings'], $payload['properties'], $extraArgs);
    }

    protected function resolveRequirements(Service $service, array $settings, array $properties): array
    {
        return [
            'slots' => $this->requiredSlots($service, $settings, $properties),
            'storage_mb' => $this->requiredStorageMb($service, $settings, $properties),
            'bandwidth_mbps' => $this->requiredBandwidthMbps($service, $settings, $properties),
        ];
    }

    protected function requiredSlots(Service $service, array $settings, array $properties): int
    {
        $merged = array_merge($settings, $properties);

        $resolved = (int) ($merged['required_slots'] ?? 0);
        if ($resolved > 0) {
            return $resolved;
        }

        $dbSetting = $service->product?->settings?->firstWhere('key', 'required_slots');
        $dbValue = (int) ($dbSetting?->value ?? 0);
        if ($dbValue > 0) {
            return $dbValue;
        }

        return 1;
    }

    protected function requiredStorageMb(Service $service, array $settings, array $properties): int
    {
        $merged = array_merge($settings, $properties);

        $resolved = (int) ($merged['required_storage_mb'] ?? 0);
        if ($resolved > 0) {
            return $resolved;
        }

        $dbSetting = $service->product?->settings?->firstWhere('key', 'required_storage_mb');

        return max(0, (int) ($dbSetting?->value ?? 0));
    }

    protected function requiredBandwidthMbps(Service $service, array $settings, array $properties): int
    {
        $merged = array_merge($settings, $properties);

        $resolved = (int) ($merged['required_bandwidth_mbps'] ?? 0);
        if ($resolved > 0) {
            return $resolved;
        }

        $dbSetting = $service->product?->settings?->firstWhere('key', 'required_bandwidth_mbps');

        return max(0, (int) ($dbSetting?->value ?? 0));
    }

    protected function buildTargetPayload(array $settings, array $properties): array
    {
        $merged = array_merge($settings, $properties);
        $targetPlan = trim((string) ($merged['target_plan'] ?? ''));

        if ($targetPlan === '') {
            throw new Exception('The product setting "target_plan" is required for Orchestrator provisioning.');
        }

        if (!isset($properties['plan']) || $properties['plan'] === '') {
            $properties['plan'] = $targetPlan;
        }

        if (!isset($properties['package']) || $properties['package'] === '') {
            $properties['package'] = $targetPlan;
        }

        if (!isset($settings['plan']) || $settings['plan'] === '') {
            $settings['plan'] = $targetPlan;
        }

        if (!isset($settings['package']) || $settings['package'] === '') {
            $settings['package'] = $targetPlan;
        }

        return [
            'settings' => $settings,
            'properties' => $properties,
        ];
    }

    protected function invokeTargetAction(object $targetExtension, string $action, Service $service, array $settings, array $properties, array $extraArgs = [])
    {
        $arguments = array_merge([$service, $settings, $properties], $extraArgs);

        try {
            return $targetExtension->{$action}(...$arguments);
        } catch (\ArgumentCountError $e) {
            return $targetExtension->{$action}($service);
        }
    }

    protected function syncOrchestratorProperties(Service $service, array $properties): void
    {
        foreach ($properties as $key => $value) {
            $service->properties()->updateOrCreate([
                'key' => $key,
            ], [
                'name' => $key,
                'value' => (string) $value,
            ]);
        }
    }
}
