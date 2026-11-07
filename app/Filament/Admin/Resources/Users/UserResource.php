<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\ManageUsers;
use App\Modules\Identity\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')->required()->maxLength(80),
            TextInput::make('email')->email()->maxLength(255),
            Select::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended'])->required(),
            Toggle::make('is_admin')->label('Platform admin'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')->label('Name')->searchable()->sortable(),
                TextColumn::make('handle')->prefix('@')->searchable(),
                TextColumn::make('phone')->searchable(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'danger'),
                IconColumn::make('is_admin')->boolean()->label('Admin'),
                TextColumn::make('followers_count')->label('Followers')->numeric()->sortable(),
                TextColumn::make('last_active_at')->since()->sortable(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'suspended' => 'Suspended']),
            ])
            ->recordActions([
                Action::make('suspend')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->status === 'active' && ! $record->is_admin)
                    ->action(function (User $record) {
                        $record->forceFill(['status' => 'suspended'])->save();
                        $record->tokens()->delete(); // signs them out everywhere
                    }),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
