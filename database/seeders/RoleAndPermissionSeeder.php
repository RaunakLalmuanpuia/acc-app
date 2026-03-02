<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // ── 1. DEFINE PERMISSIONS ──────────────────────────────────────
        $permissionsMap = [
            'company'           => ['view', 'create', 'update', 'delete', 'manage settings'],
            'bank_account'      => ['view', 'create', 'update', 'delete', 'import statements'],
            'transaction'       => ['view', 'create', 'update', 'delete', 'reconcile', 'bulk categorize'],
            'invoice'           => ['view', 'create', 'update', 'delete', 'send', 'record payment'],
            'client'            => ['view', 'create', 'update', 'delete'],
            'accounting_config' => ['manage heads', 'manage rules', 'view logs'],
            'user'              => ['view', 'create', 'update', 'delete', 'manage roles'],
            'agent'             => ['chat'], // Added for your AgentController
        ];

        foreach ($permissionsMap as $group => $actions) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "{$action} {$group}",
                    'guard_name' => 'web'
                ]);
            }
        }

        // ── 2. CREATE ROLES ───────────────────────────────────────────

        // Super Admin & Owner
        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $owner      = Role::firstOrCreate(['name' => 'owner']);

        $allPermissions = Permission::all();
        $superAdmin->syncPermissions($allPermissions);
        $owner->syncPermissions($allPermissions);

        // Accountant
        $accountant = Role::firstOrCreate(['name' => 'accountant']);
        $accountant->syncPermissions([
            'view company',
            'view bank_account',
            'import statements bank_account', // Fixed name
            'view transaction',
            'update transaction',
            'reconcile transaction',          // Fixed name
            'bulk categorize transaction',    // Fixed name
            'view invoice',
            'record payment invoice',         // Fixed name
            'view client',
            'manage heads accounting_config', // Fixed name
            'manage rules accounting_config', // Fixed name
            'chat agent'                      // Added for your chat feature
        ]);

        // Clerk
        $clerk = Role::firstOrCreate(['name' => 'clerk']);
        $clerk->syncPermissions([
            'view bank_account',
            'view transaction',
            'create transaction',
            'view invoice',
            'create invoice',
            'view client',
            'create client'
        ]);

        $this->command?->info('✓ Roles and Permissions seeded successfully.');
    }
}
