<?php

namespace App\Filament\Admin\Resources\Posts;

use App\Filament\Admin\Resources\Posts\Pages\ManagePosts;
use App\Modules\Social\Models\Post;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Content moderation. Hidden posts disappear from every feed but can be restored. */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author_type')->badge(),
                TextColumn::make('caption')->limit(60)->searchable(),
                TextColumn::make('event_type')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'published' => 'success', 'hidden' => 'warning', default => 'danger',
                }),
                TextColumn::make('likes_count')->label('Likes')->numeric()->sortable(),
                TextColumn::make('shop_taps_count')->label('Shop taps')->numeric()->sortable(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(['published' => 'Published', 'hidden' => 'Hidden', 'removed' => 'Removed']),
                SelectFilter::make('event_type')->options(['wedding' => 'Wedding', 'birthday' => 'Birthday', 'corporate' => 'Corporate', 'other' => 'Other']),
            ])
            ->recordActions([
                Action::make('hide')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Post $record) => $record->status === 'published')
                    ->action(fn (Post $record) => $record->update(['status' => 'hidden'])),
                Action::make('restore')
                    ->visible(fn (Post $record) => $record->status === 'hidden')
                    ->action(fn (Post $record) => $record->update(['status' => 'published'])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePosts::route('/')];
    }
}
