<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class FiscalLookupSeeder extends Seeder
{
    /**
     * Seed default data for fiscal module.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'checkbox.robot@example.com'],
            [
                'name' => 'Checkbox Robot',
                'password' => Hash::make('change_me_please'),
            ]
        );
    }
}
