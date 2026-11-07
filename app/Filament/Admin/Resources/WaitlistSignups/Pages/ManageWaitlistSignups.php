<?php

namespace App\Filament\Admin\Resources\WaitlistSignups\Pages;

use App\Filament\Admin\Resources\WaitlistSignups\WaitlistSignupResource;
use App\Modules\Marketing\Models\WaitlistSignup;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageWaitlistSignups extends ManageRecords
{
    protected static string $resource = WaitlistSignupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => $this->exportCsv()),
        ];
    }

    /**
     * Streams the rows matching the table's current search and filters, oldest
     * first (ULIDs sort by creation time, which lets us page by id).
     */
    public function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['joined_at', 'role', 'name', 'whatsapp_phone', 'city', 'business_name', 'category', 'event_types', 'utm_source', 'utm_medium', 'utm_campaign']);

            $query->lazyById(500)->each(function (WaitlistSignup $s) use ($out) {
                fputcsv($out, [
                    $s->created_at?->toIso8601String(),
                    $s->role->value,
                    self::safeCell($s->name),
                    $s->whatsapp_phone, // normalised E.164, so the leading + is expected
                    self::safeCell($s->city),
                    self::safeCell($s->business_name),
                    $s->category,
                    implode('|', $s->event_types ?? []),
                    self::safeCell($s->source['utm_source'] ?? null),
                    self::safeCell($s->source['utm_medium'] ?? null),
                    self::safeCell($s->source['utm_campaign'] ?? null),
                ]);
            });

            fclose($out);
        }, 'waitlist-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Free text from the public form ends up in spreadsheets: stop it running as a formula. */
    public static function safeCell(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
