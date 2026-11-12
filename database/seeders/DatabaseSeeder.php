<?php

namespace Database\Seeders;

use App\Support\Demo\DemoGenerator;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development, generated with a fixed seed (documentation/DEMO.md). All
 * accounts have the password of UserFactory::PASSWORD and the pin UserFactory::PIN.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(DemoGenerator $generator): void
    {
        $generator->run();
    }
}
