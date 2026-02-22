<?php

namespace Paymenter\Extensions\Servers\Orchestrator\Admin\Resources;

use App\Models\Server;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\OrchestratorServerPoolResource\Pages\CreateOrchestratorServerPool;
use Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\OrchestratorServerPoolResource\Pages\EditOrchestratorServerPool;
use Paymenter\Extensions\Servers\Orchestrator\Admin\Resources\OrchestratorServerPoolResource\Pages\ListOrchestratorServerPools;
use Paymenter\Extensions\Servers\Orchestrator\Models\OrchestratorServerPool;

class OrchestratorServerPoolResource extends Resource
{
    protected static ?string $model = OrchestratorServerPool::class;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-database-2-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-database-2-fill';

    protected static string|\UnitEnum|null $navigationGroup = 'Orchestrator';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('orchestrator_server_id')
                    ->label('Orchestrator Server')
                    ->required()
                    ->searchable()
                    ->options(fn () => Server::query()
                        ->where('type', 'server')
                        ->whereRaw('LOWER(extension) = ?', ['orchestrator'])
                        ->pluck('name', 'id')
                        ->toArray()
                    ),
                Select::make('target_server_id')
                    ->label('Target Server')
                    ->required()
                    ->searchable()
                    ->helperText('Important: target servers must already have plans/packages created with the same names configured in each Orchestrator product (target_plan), otherwise provisioning can fail.')
                    ->options(fn () => Server::query()
                        ->where('type', 'server')
                        ->whereRaw('LOWER(extension) != ?', ['orchestrator'])
                        ->pluck('name', 'id')
                        ->toArray()
                    ),
                TextInput::make('total_slots')
                    ->label('Total Slots')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('maintenance')
                    ->label('Maintenance')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('orchestratorServer.name')
                    ->label('Orchestrator')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('targetServer.name')
                    ->label('Target')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total_slots')
                    ->label('Total Slots')
                    ->sortable(),
                TextColumn::make('used_slots')
                    ->label('Used Slots')
                    ->state(fn (OrchestratorServerPool $record) => (int) $record->allocations()->sum('slots')),
                TextColumn::make('available_slots')
                    ->label('Available Slots')
                    ->state(function (OrchestratorServerPool $record) {
                        $usedSlots = (int) $record->allocations()->sum('slots');

                        return max(0, (int) $record->total_slots - $usedSlots);
                    }),
                TextColumn::make('usage_percentage')
                    ->label('Usage %')
                    ->state(function (OrchestratorServerPool $record) {
                        $totalSlots = (int) $record->total_slots;
                        if ($totalSlots <= 0) {
                            return '0%';
                        }

                        $usedSlots = (int) $record->allocations()->sum('slots');

                        return number_format(($usedSlots / $totalSlots) * 100, 2) . '%';
                    }),
                IconColumn::make('maintenance')
                    ->label('Maintenance')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrchestratorServerPools::route('/'),
            'create' => CreateOrchestratorServerPool::route('/create'),
            'edit' => EditOrchestratorServerPool::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }
}
