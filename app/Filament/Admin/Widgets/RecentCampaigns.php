<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Campaigns\CampaignResource;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\SendWindow;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/** The dashboard's last 30 days of campaigns and automations, with their reach. */
class RecentCampaigns extends TableWidget
{
    protected static ?string $heading = 'Campaigns, last 30 days';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) Auth::user()?->hasAdminPermission('campaigns');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Campaign::query()
                ->where('last_run_at', '>=', now()->subDays(30))
                ->withCount([
                    'recipients as total_count' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30)),
                    'recipients as reached_count' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30))->whereIn('status', ['sent', 'delivered']),
                    'recipients as delivered_count' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30))->where('status', 'delivered'),
                    'recipients as skipped_count' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30))->where('status', 'skipped'),
                    'recipients as opened_count' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30))->whereNotNull('opened_at'),
                ])
                ->withSum(['recipients as sms_cost' => fn ($q) => $q->where('created_at', '>=', now()->subDays(30))], 'cost_minor'))
            ->columns([
                TextColumn::make('name')->url(fn (Campaign $record) => CampaignResource::getUrl('view', ['record' => $record]))
                    ->description(fn (Campaign $record) => $record->isAutomation() ? 'Automation' : null),
                TextColumn::make('last_run_at')->label('Last sent')->dateTime('j M, H:i', SendWindow::timezone())->sortable(),
                TextColumn::make('total_count')->label('Audience')->numeric(),
                TextColumn::make('reached_count')->label('Sent')->numeric(),
                TextColumn::make('delivered_count')->label('Delivered')->numeric(),
                TextColumn::make('skipped_count')->label('Skipped')->numeric(),
                TextColumn::make('opened_count')->label('Opened')->numeric(),
                TextColumn::make('sms_cost')->label('SMS cost')
                    ->formatStateUsing(fn ($state) => Money::format((int) $state, config('eventapp.currency'))),
            ])
            ->defaultSort('last_run_at', 'desc')
            ->paginated([10]);
    }
}
