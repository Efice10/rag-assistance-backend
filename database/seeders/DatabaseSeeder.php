<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo tenants and accounts for local development. Every account uses the
     * password "password". Never run this seeder against production.
     */
    public function run(): void
    {
        $tenants = [
            [
                'name' => 'Acme Manufacturing',
                'slug' => 'acme',
                'departments' => ['Procurement', 'Human Resources', 'Compliance'],
                'users' => [
                    ['Alice Admin', 'admin@acme.test', User::ROLE_ADMIN, 4, 'Compliance'],
                    ['Mark Manager', 'manager@acme.test', User::ROLE_MANAGER, 3, 'Procurement'],
                    ['Sam Staff', 'staff@acme.test', User::ROLE_STAFF, 1, 'Procurement'],
                    ['Hana HR', 'hr@acme.test', User::ROLE_MANAGER, 3, 'Human Resources'],
                ],
            ],
            [
                'name' => 'Globex Logistics',
                'slug' => 'globex',
                'departments' => ['Operations', 'Finance'],
                'users' => [
                    ['Gina Admin', 'admin@globex.test', User::ROLE_ADMIN, 4, 'Operations'],
                    ['Omar Manager', 'manager@globex.test', User::ROLE_MANAGER, 3, 'Operations'],
                    ['Faye Staff', 'staff@globex.test', User::ROLE_STAFF, 2, 'Finance'],
                ],
            ],
        ];

        foreach ($tenants as $tenant) {
            $organization = Organization::updateOrCreate(
                ['slug' => $tenant['slug']],
                ['name' => $tenant['name']],
            );

            $departments = collect($tenant['departments'])->mapWithKeys(fn (string $name) => [
                $name => Department::firstOrCreate(['organization_id' => $organization->id, 'name' => $name]),
            ]);

            foreach ($tenant['users'] as [$name, $email, $role, $clearance, $department]) {
                User::updateOrCreate(
                    ['email' => $email],
                    [
                        'organization_id' => $organization->id,
                        'department_id' => $departments[$department]->id,
                        'name' => $name,
                        'password' => 'password',
                        'role' => $role,
                        'clearance_level' => $clearance,
                        'email_verified_at' => now(),
                    ],
                );
            }
        }
    }
}
