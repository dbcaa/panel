<?php

namespace App\Filament\Resources\ServerResource\Pages;

use App\Filament\Resources\ServerResource;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Tables;

class ListServers extends ListRecords
{
    protected static string $resource = ServerResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->defaultGroup('node.name')
            ->groups([
                Group::make('node.name')->getDescriptionFromRecordUsing(fn (Server $server): string => str($server->node->description)->limit(150)),
                Group::make('user.username')->getDescriptionFromRecordUsing(fn (Server $server): string => $server->user->email),
                Group::make('egg.name')->getDescriptionFromRecordUsing(fn (Server $server): string => str($server->egg->description)->limit(150)),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->default('unknown')
                    ->badge()
                    ->default(fn (Server $server) => $server->status ?? $server->retrieveStatus())
                    ->icon(fn ($state) => match ($state) {
                        'node_fail' => 'tabler-server-off',
                        'running' => 'tabler-heartbeat',
                        'removing' => 'tabler-heart-x',
                        'offline' => 'tabler-heart-off',
                        'paused' => 'tabler-heart-pause',
                        'installing' => 'tabler-heart-bolt',
                        'suspended' => 'tabler-heart-cancel',
                        default => 'tabler-heart-question',
                    })
                    ->color(fn ($state): string => match ($state) {
                        'running' => 'success',
                        'installing', 'restarting' => 'primary',
                        'paused', 'removing' => 'warning',
                        'node_fail', 'install_failed', 'suspended' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('uuid')
                    ->hidden()
                    ->label('UUID')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->icon('tabler-brand-docker')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('node.name')
                    ->icon('tabler-server-2')
                    ->url(fn (Server $server): string => route('filament.admin.resources.nodes.edit', ['record' => $server->node]))
                    ->hidden(fn (Table $table) => $table->getGrouping()->getId() === 'node.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('egg.name')
                    ->icon('tabler-egg')
                    ->url(fn (Server $server): string => route('filament.admin.resources.eggs.edit', ['record' => $server->egg]))
                    ->hidden(fn (Table $table) => $table->getGrouping()->getId() === 'egg.name')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.username')
                    ->icon('tabler-user')
                    ->label('Owner')
                    ->url(fn (Server $server): string => route('filament.admin.resources.users.edit', ['record' => $server->user]))
                    ->hidden(fn (Table $table) => $table->getGrouping()->getId() === 'user.username')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ports'),
            ])
            ->actions([
                Tables\Actions\Action::make('View')
                    ->icon('tabler-terminal')
                    ->url(fn (Server $server) => "/server/$server->uuid_short"),
                Tables\Actions\EditAction::make(),
            ])
            ->emptyStateIcon('tabler-brand-docker')
            ->searchable()
            ->emptyStateDescription('')
            ->emptyStateHeading('No Servers')
            ->emptyStateActions([
                CreateAction::make('create')
                    ->label('Create Server')
                    ->button(),
            ]);
    }
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Create Server')
                ->hidden(fn () => Server::count() <= 0),
        ];
    }
}
