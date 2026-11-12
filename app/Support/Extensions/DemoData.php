<?php

namespace App\Support\Extensions;

use App\Support\Demo\DemoPart;
use App\Support\Modules\ModuleRegistry;

/**
 * Extension point for demo data. The core adds its parts (inventory, labels), every module adds
 * its own part. Parts run in their order with one App\Support\Demo\DemoContext, so a later part
 * can use what an earlier one created. A switched off module adds nothing.
 */
class DemoData
{
    /**
     * @var list<array{class: class-string<DemoPart>, module: string|null, order: int}>
     */
    private array $parts = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * Add a part of the demo data.
     *
     * @param  class-string<DemoPart>  $class
     * @param  string|null  $module  key of the module, the part is skipped while the module is off
     */
    public function add(string $class, ?string $module = null, int $order = 100): void
    {
        $this->parts[] = compact('class', 'module', 'order');
    }

    /**
     * Classes of the parts of the core and of the active modules, in their order.
     *
     * @return list<class-string<DemoPart>>
     */
    public function parts(): array
    {
        $parts = array_filter($this->parts, fn (array $part) => $part['module'] === null || $this->modules->isActive($part['module']));
        usort($parts, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_column($parts, 'class');
    }
}
