<?php

namespace App\Filament\Admin\Resources\Reviews;

use App\Filament\Admin\Resources\Reviews\Pages\ManageReviews;
use App\Modules\Reviews\Enums\ReviewDirection;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Services\ReviewModeration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Review moderation. Reviews are removed only for breaking the content rules (abuse, private
 * details, spam), never for being negative; removal keeps the review, the reason and who did it.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('vendor', 'customer')
            ->withCount(['reports as open_reports_count' => fn (Builder $q) => $q->where('status', 'open')]);
    }

    public static function table(Table $table): Table
    {
        $reasons = config('eventapp.reviews.removal_reasons');

        return $table
            ->columns([
                TextColumn::make('direction')->badge()
                    ->formatStateUsing(fn (ReviewDirection $state) => $state === ReviewDirection::CustomerToVendor ? 'Of vendor' : 'Of customer'),
                TextColumn::make('vendor.name')->searchable(),
                TextColumn::make('customer.display_name')->label('Customer'),
                TextColumn::make('rating')->formatStateUsing(fn (int $state) => str_repeat('★', $state))->sortable(),
                TextColumn::make('body')->limit(80)->wrap()->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ReviewStatus $state) => ucfirst($state->value))
                    ->color(fn (ReviewStatus $state) => match ($state) {
                        ReviewStatus::Published => 'success', ReviewStatus::Pending => 'gray', ReviewStatus::Removed => 'danger',
                    }),
                TextColumn::make('open_reports_count')->label('Reports')->numeric()->sortable(),
                TextColumn::make('removed_reason')->label('Removed for')
                    ->formatStateUsing(fn (?string $state) => $reasons[$state] ?? $state)->toggleable(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(ReviewStatus::cases())->mapWithKeys(fn (ReviewStatus $s) => [$s->value => ucfirst($s->value)])),
                SelectFilter::make('rating')->options([5 => '5 ★', 4 => '4 ★', 3 => '3 ★', 2 => '2 ★', 1 => '1 ★']),
                SelectFilter::make('direction')->options(['customer_to_vendor' => 'Of vendor', 'vendor_to_customer' => 'Of customer']),
                Filter::make('reported')->label('Has open reports')
                    ->query(fn (Builder $query) => $query->whereHas('reports', fn (Builder $q) => $q->where('status', 'open'))),
            ])
            ->recordActions([
                Action::make('remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (Review $record) => $record->status !== ReviewStatus::Removed)
                    ->modalDescription('Only for breaking the content rules, never for being negative.')
                    ->schema([
                        Select::make('reason')->options($reasons)->required(),
                        Textarea::make('note')->label('Note (kept with the review)'),
                    ])
                    ->action(function (Review $record, array $data) {
                        app(ReviewModeration::class)->remove($record, Auth::user(), $data['reason'], $data['note'] ?? null);
                        Notification::make()->title('Review removed')->success()->send();
                    }),
                Action::make('dismissReports')
                    ->label('Dismiss reports')
                    ->visible(fn (Review $record) => $record->status !== ReviewStatus::Removed && $record->open_reports_count > 0)
                    ->requiresConfirmation()
                    ->action(fn (Review $record) => app(ReviewModeration::class)->dismissReports($record)),
                Action::make('restore')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (Review $record) => $record->status === ReviewStatus::Removed)
                    ->requiresConfirmation()
                    ->action(function (Review $record) {
                        app(ReviewModeration::class)->restore($record);
                        Notification::make()->title('Review restored')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageReviews::route('/')];
    }
}
