<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            [
                'name' => 'Chester Francisco',
                'email' => 'chester@mail.dev',
                'password' => Hash::make('a'),
                'role' => 'admin',
            ],
            [
                'name' => 'Janjan Cagadas',
                'email' => 'janjan@mail.dev',
                'password' => Hash::make('a'),
                'role' => 'admin',
            ],
            [
                'name' => 'Etienne Amparado',
                'email' => 'eshen@mail.dev',
                'password' => Hash::make('a'),
                'role' => 'user',
            ],
            [
                'name' => 'Joram Kate Leonardo',
                'email' => 'joram@mail.dev',
            'password' => Hash::make('a'),
                'role' => 'user',
            ],

        ];

        User::insert($data);
    }
}
