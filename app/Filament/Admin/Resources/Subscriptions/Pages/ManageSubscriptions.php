<?php

namespace App\Filament\Admin\Resources\Subscriptions\Pages;

use App\Filament\Admin\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Admin\Widgets\SubscriptionRevenue;
use App\Modules\Identity\Models\Vendor;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageSubscriptions extends ManageRecords
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [SubscriptionRevenue::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Give any vendor a plan for free, e.g. a launch partner.
            Action::make('comp')
                ->label('Comp a plan')
                ->schema([
                    Select::make('vendor_id')->label('Vendor')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Vendor::where('name', 'like', "%{$search}%")->limit(20)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value) => Vendor::find($value)?->name),
                    Select::make('plan')->options(Plan::where('price_monthly_minor', '>', 0)->ordered()->pluck('name', 'key'))->required(),
                    TextInput::make('days')->integer()->minValue(1)->maxValue(366)->required(),
                    TextInput::make('reason')->required()->maxLength(255),
                ])
                ->action(function (array $data) {
                    app(SubscriptionService::class)->comp(Vendor::findOrFail($data['vendor_id']), Plan::byKey($data['plan']), (int) $data['days'], $data['reason'], Auth::user());
                    Notification::make()->title('Plan given')->success()->send();
                }),
        ];
    }
}
