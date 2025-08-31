<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\State;

class StateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $states = [
            ['name' => 'Abia', 'country_id' => 'NG'],
            ['name' => 'Adamawa', 'country_id' => 'NG'],
            ['name' => 'Akwa Ibom', 'country_id' => 'NG'],
            ['name' => 'Anambra', 'country_id' => 'NG'],
            ['name' => 'Bauchi', 'country_id' => 'NG'],
            ['name' => 'Bayelsa', 'country_id' => 'NG'],
            ['name' => 'Benue', 'country_id' => 'NG'],
            ['name' => 'Borno', 'country_id' => 'NG'],
            ['name' => 'Cross River', 'country_id' => 'NG'],
            ['name' => 'Delta', 'country_id' => 'NG'],
            ['name' => 'Ebonyi', 'country_id' => 'NG'],
            ['name' => 'Edo', 'country_id' => 'NG'],
            ['name' => 'Ekiti', 'country_id' => 'NG'],
            ['name' => 'Enugu', 'country_id' => 'NG'],
            ['name' => 'FCT', 'country_id' => 'NG'],
            ['name' => 'Gombe', 'country_id' => 'NG'],
            ['name' => 'Imo', 'country_id' => 'NG'],
            ['name' => 'Jigawa', 'country_id' => 'NG'],
            ['name' => 'Kaduna', 'country_id' => 'NG'],
            ['name' => 'Kano', 'country_id' => 'NG'],
            ['name' => 'Katsina', 'country_id' => 'NG'],
            ['name' => 'Kebbi', 'country_id' => 'NG'],
            ['name' => 'Kogi', 'country_id' => 'NG'],
            ['name' => 'Kwara', 'country_id' => 'NG'],
            ['name' => 'Lagos', 'country_id' => 'NG'],
            ['name' => 'Nasarawa', 'country_id' => 'NG'],
            ['name' => 'Niger', 'country_id' => 'NG'],
            ['name' => 'Ogun', 'country_id' => 'NG'],
            ['name' => 'Ondo', 'country_id' => 'NG'],
            ['name' => 'Osun', 'country_id' => 'NG'],
            ['name' => 'Oyo', 'country_id' => 'NG'],
            ['name' => 'Plateau', 'country_id' => 'NG'],
            ['name' => 'Rivers', 'country_id' => 'NG'],
            ['name' => 'Sokoto', 'country_id' => 'NG'],
            ['name' => 'Taraba', 'country_id' => 'NG'],
            ['name' => 'Yobe', 'country_id' => 'NG'],
            ['name' => 'Zamfara', 'country_id' => 'NG'],
        ];

        foreach ($states as $state) {
            State::create($state);
        }
    }
}