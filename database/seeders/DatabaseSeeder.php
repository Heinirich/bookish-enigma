<?php

namespace Database\Seeders;

use App\Models\MonitoredEndpoint;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'heinrich@quickorganics.com'],
            ['name' => 'Heinrich', 'password' => Hash::make('password')],
        );

        MonitoredEndpoint::firstOrCreate(
            ['slug' => 'payments-api'],
            [
                'name' => 'Payments API health',
                'url' => config('health.target_url'),
                'service' => 'payments-api',
                'interval_seconds' => 10,
                'expected_status' => 200,
                'is_active' => true,
            ],
        );
    }
}
