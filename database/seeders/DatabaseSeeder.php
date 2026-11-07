<?php

namespace Database\Seeders;

use App\Modules\Events\Models\Event;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development.
 *   php artisan db:seed            SQL: admin, users, vendors, events
 *   php artisan db:seed --class=CatalogSeeder   MongoDB: categories and items
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin dashboard: /admin  (admin@eventapp.test / password)
        User::factory()->admin()->create([
            'handle' => 'admin.team',
            'display_name' => 'Event App Admin',
            'phone' => '+233200000001',
            'email' => 'admin@eventapp.test',
        ]);

        // A vendor owner you can sign in as from the app (OTP 123456 locally),
        // and on the vendor dashboard /vendor (vendor@eventapp.test / password).
        $owner = User::factory()->create([
            'handle' => 'kojo.owner',
            'display_name' => 'Kojo Asante',
            'phone' => '+233200000002',
            'email' => 'vendor@eventapp.test',
            'password' => 'password',
        ]);

        Vendor::factory()->ownedBy($owner)->create([
            'handle' => 'royal.canopies',
            'name' => 'Royal Canopies & Chairs',
            'categories' => ['tents', 'chairs', 'tables'],
        ]);
        Vendor::factory()->ownedBy()->create(['handle' => 'glow.decor', 'name' => 'Glow Decor', 'categories' => ['decor', 'lighting']]);
        Vendor::factory()->ownedBy()->create(['handle' => 'bass.sound', 'name' => 'Bass Sound & Lights', 'categories' => ['sound', 'lighting']]);

        // A host with an upcoming wedding.
        $host = User::factory()->create(['handle' => 'esi.host', 'display_name' => 'Esi Mensah', 'phone' => '+233200000003']);
        Event::factory()->create(['host_id' => $host->id, 'type' => 'wedding', 'title' => 'Kofi & Esi’s Wedding']);

        User::factory(10)->create();
    }
}
