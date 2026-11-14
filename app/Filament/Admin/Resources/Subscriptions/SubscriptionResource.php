<?php

namespace App\Filament\Admin\Resources\Subscriptions;

use App\Filament\Admin\Resources\Subscriptions\Pages\ManageSubscriptions;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\VendorSubscription;
use App\Modules\Subscriptions\Services\SubscriptionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Every vendor subscription, current and past. Vendors with none are on Starter. */
class SubscriptionResource extends Resource
{
    protected static ?string $model = VendorSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('vendor', 'plan', 'scheduledPlan');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor.name')->searchable(),
                TextColumn::make('plan.name')->badge(),
                TextColumn::make('interval'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state) => ucfirst($state->value))
                    ->color(fn (SubscriptionStatus $state) => match ($state) {
                        SubscriptionStatus::Active => 'success', SubscriptionStatus::Trialing => 'info',
                        SubscriptionStatus::Grace => 'warning', default => 'gray',
                    }),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('payment_method')->placeholder('—'),
                TextColumn::make('current_period_end')->label('Period ends')->dateTime()->sortable(),
                TextColumn::make('scheduledPlan.name')->label('Changes to')->placeholder('—'),
                IconColumn::make('cancel_at_period_end')->label('Cancelling')->boolean(),
                TextColumn::make('end_reason')->placeholder('—')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_current')->label('Current')->default(true),
                SelectFilter::make('status')->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])),
                SelectFilter::make('plan_id')->label('Plan')->options(fn () => Plan::ordered()->pluck('name', 'id')),
                SelectFilter::make('interval')->options(['month' => 'Monthly', 'year' => 'Yearly']),
                SelectFilter::make('payment_method')->options(['card' => 'Card', 'mobile_money' => 'Mobile money']),
            ])
            ->recordActions([
                Action::make('extend')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (VendorSubscription $record) => $record->is_current && $record->status->givesBenefits())
                    ->schema([
                        TextInput::make('days')->integer()->minValue(1)->maxValue(366)->required(),
                        TextInput::make('reason')->required()->maxLength(255),
                    ])
                    ->action(function (VendorSubscription $record, array $data) {
                        app(SubscriptionService::class)->extend($record, (int) $data['days'], $data['reason'], Auth::user());
                        Notification::make()->title('Extended')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSubscriptions::route('/')];
    }
}
