<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * For demo data, run separately:
     *   php artisan db:seed --class=DemoOrganizationSeeder
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            AccountTypeSeeder::class,
        ]);
    }
}
