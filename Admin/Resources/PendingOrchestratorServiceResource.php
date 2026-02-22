<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Admin\Resources;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\PendingOrchestratorServiceResource\Pages\ListPendingOrchestratorServices;
use Paymenter\Extensions\Servers\Orchestrator\Models\OrchestratorServerPool;
use Paymenter\Extensions\Servers\Orchestrator\Models\OrchestratorServiceAllocation;

class PendingOrchestratorServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-hourglass-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-hourglass-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Orchestrator';

    protected static ?string $navigationLabel = 'Orchestrator Pending Deploy';

    public static function getNavigationBadge(): ?string
    {
        return static::getEloquentQuery()->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Service ID')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label('Client')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('required_slots')
                    ->label('Required Slots')
                    ->state(fn (Service $record) => static::requiredSlotsForService($record)),
                TextColumn::make('pending_reason')
                    ->label('Reason')
                    ->badge()
                    ->state(fn (Service $record) => static::pendingReasonForService($record)),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since(),
            ])
            ->filters([
                Filter::make('pending_type')
                    ->label('Pending Type')
                    ->form([
                        Select::make('type')
                            ->label('Type')
                            ->options([
                                'all' => 'All pending',
                                'payment' => 'Payment required',
                                'error' => 'Creation error',
                                'no_capacity' => 'No capacity',
                                'creating' => 'Creating now',
                            ])
                            ->default('all'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $type = $data['type'] ?? null;

                        return match ($type) {
                            'payment' => static::applyPendingPaymentFilter($query),
                            'error' => static::applyPendingErrorFilter($query),
                            'no_capacity' => static::applyPendingNoCapacityFilter($query),
                            'creating' => static::applyCreatingFilter($query),
                            'all' => $query,
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('deploy')
                    ->label('Deploy')
                    ->icon('ri-upload-cloud-2-line')
                    ->requiresConfirmation()
                    ->form([
                        Select::make('orchestrator_server_pool_id')
                            ->label('Target Server')
                            ->required()
                            ->searchable()
                            ->placeholder('No hay espacio en ningún lado')
                            ->options(fn (Service $record) => static::availablePoolOptions($record)),
                    ])
                    ->action(function (Service $record, array $data): void {
                        $pool = OrchestratorServerPool::with('targetServer')
                            ->whereKey($data['orchestrator_server_pool_id'])
                            ->first();

                        if (!$pool || !$pool->targetServer) {
                            Notification::make()
                                ->title('Invalid target server')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ((int) $pool->orchestrator_server_id !== (int) $record->product->server_id) {
                            Notification::make()
                                ->title('Selected pool does not belong to this orchestrator server')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ((bool) $pool->maintenance) {
                            Notification::make()
                                ->title('Selected server is in maintenance mode')
                                ->danger()
                                ->send();

                            return;
                        }

                        $requiredSlots = static::requiredSlotsForService($record);
                        $usedSlots = (int) $pool->allocations()->sum('slots');
                        $availableSlots = (int) $pool->total_slots - $usedSlots;

                        if ($availableSlots < $requiredSlots) {
                            Notification::make()
                                ->title('No capacity available in selected server')
                                ->danger()
                                ->send();

                            return;
                        }

                        $existingAllocation = OrchestratorServiceAllocation::where('service_id', $record->id)->first();
                        if ($existingAllocation && (int) $existingAllocation->orchestrator_server_pool_id !== (int) $pool->id) {
                            Notification::make()
                                ->title('Service already has an orchestrator allocation')
                                ->warning()
                                ->send();

                            return;
                        }

                        $createdAllocation = false;

                        if (!$existingAllocation) {
                            $existingAllocation = OrchestratorServiceAllocation::create([
                                'orchestrator_server_pool_id' => $pool->id,
                                'service_id' => $record->id,
                                'target_server_id' => $pool->target_server_id,
                                'slots' => $requiredSlots,
                            ]);
                            $createdAllocation = true;
                        }

                        try {
                            ExtensionHelper::createServer($record);

                            Notification::make()
                                ->title('Service deployed successfully')
                                ->success()
                                ->send();
                        } catch (Exception $e) {
                            if ($createdAllocation) {
                                $existingAllocation->delete();
                            }

                            report($e);

                            Notification::make()
                                ->title('Deploy failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', Service::STATUS_PENDING)
            ->whereHas('product.server', function (Builder $query) {
                $query->whereRaw('LOWER(extension) = ?', ['orchestrator']);
            });
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPendingOrchestratorServices::route('/'),
        ];
    }

    protected static function availablePoolOptions(Service $service): array
    {
        $requiredSlots = static::requiredSlotsForService($service);

        return OrchestratorServerPool::with('targetServer')
            ->where('orchestrator_server_id', (int) $service->product->server_id)
            ->where('maintenance', false)
            ->get()
            ->filter(function (OrchestratorServerPool $pool) use ($requiredSlots) {
                if (!$pool->targetServer || strtolower($pool->targetServer->extension) === 'orchestrator') {
                    return false;
                }

                $usedSlots = (int) $pool->allocations()->sum('slots');
                $availableSlots = (int) $pool->total_slots - $usedSlots;

                return $availableSlots >= $requiredSlots;
            })
            ->mapWithKeys(function (OrchestratorServerPool $pool) {
                $usedSlots = (int) $pool->allocations()->sum('slots');
                $availableSlots = (int) $pool->total_slots - $usedSlots;
                $label = sprintf('%s (available: %d / total: %d)', $pool->targetServer->name, $availableSlots, (int) $pool->total_slots);

                return [$pool->id => $label];
            })
            ->toArray();
    }

    protected static function requiredSlotsForService(Service $service): int
    {
        $settings = ExtensionHelper::settingsToArray($service->product->settings);
        $properties = ExtensionHelper::getServiceProperties($service);
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

    protected static function pendingReasonForService(Service $service): string
    {
        $state = (string) ($service->properties()->where('key', 'orchestrator_provisioning_state')->value('value') ?? '');
        if ($state === 'creating') {
            return 'Creating now';
        }

        $reason = (string) ($service->properties()->where('key', 'orchestrator_pending_reason')->value('value') ?? '');

        return match ($reason) {
            'error' => 'Creation error',
            'no_capacity' => 'No capacity',
            default => $service->invoices()->where('status', Invoice::STATUS_PENDING)->exists()
                ? 'Payment required'
                : 'Pending',
        };
    }

    protected static function applyPendingPaymentFilter(Builder $query): Builder
    {
        return $query
            ->whereHas('invoices', fn (Builder $invoiceQuery) => $invoiceQuery->where('status', Invoice::STATUS_PENDING))
            ->whereDoesntHave('properties', function (Builder $propertyQuery) {
                $propertyQuery
                    ->where('key', 'orchestrator_provisioning_state')
                    ->where('value', 'creating');
            })
            ->whereDoesntHave('properties', function (Builder $propertyQuery) {
                $propertyQuery
                    ->where('key', 'orchestrator_pending_reason')
                    ->whereIn('value', ['no_capacity', 'error']);
            });
    }

    protected static function applyPendingErrorFilter(Builder $query): Builder
    {
        return $query->whereHas('properties', function (Builder $propertyQuery) {
            $propertyQuery
                ->where('key', 'orchestrator_pending_reason')
                ->where('value', 'error');
        });
    }

    protected static function applyPendingNoCapacityFilter(Builder $query): Builder
    {
        return $query->whereHas('properties', function (Builder $propertyQuery) {
            $propertyQuery
                ->where('key', 'orchestrator_pending_reason')
                ->where('value', 'no_capacity');
        });
    }

    protected static function applyCreatingFilter(Builder $query): Builder
    {
        return $query->whereHas('properties', function (Builder $propertyQuery) {
            $propertyQuery
                ->where('key', 'orchestrator_provisioning_state')
                ->where('value', 'creating');
        });
    }
}
