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
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            RoleAndPermissionSeeder::class,
            CompanySeeder::class,
            ClientSeeder::class,
            InventoryItemSeeder::class,
            BankAccountSeeder::class,
//            InvoiceSeeder::class,
//            BankTransactionSeeder::class,
        ]);
    }
}
