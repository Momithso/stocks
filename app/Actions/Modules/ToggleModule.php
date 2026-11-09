<?php

namespace App\Actions\Modules;

use App\Models\Module;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Switch a module on or off.
 */
class ToggleModule
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * A module can only be switched on if the modules it requires are on, and only be switched
     * off if no active module requires it.
     */
    public function handle(string $key, bool $active): void
    {
        $manifest = $this->modules->find($key) ?? abort(404);

        if ($active && $missing = $this->modules->missingRequirements($key)) {
            throw ValidationException::withMessages(['module' => "{$manifest->name} braucht zuerst: ".$this->names($missing).'.']);
        }

        if (! $active && $dependents = $this->modules->activeDependents($key)) {
            throw ValidationException::withMessages(['module' => "{$manifest->name} wird noch gebraucht von: ".$this->names($dependents).'.']);
        }

        Module::where('key', $key)->firstOrFail()->update(['active' => $active]);
        $this->modules->flush();
    }

    /**
     * @param  list<string>  $keys
     */
    private function names(array $keys): string
    {
        return implode(', ', array_map(fn (string $key) => $this->modules->find($key)->name ?? $key, $keys));
    }
}
