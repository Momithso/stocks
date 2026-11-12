<?php

namespace App\Support\Demo;

/**
 * A part of the demo data, e.g. the inventory or the loans of a module. See
 * App\Support\Extensions\DemoData and documentation/DEMO.md.
 */
interface DemoPart
{
    /**
     * Create the data of this part. Random choices come from $demo, never from rand() or Faker,
     * so the same seed always gives the same data.
     */
    public function run(DemoContext $demo): void;
}
