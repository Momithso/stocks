<?php

namespace App\Support\Modules;

use InvalidArgumentException;

/**
 * Description of a module, read from its module.json.
 */
final readonly class ModuleManifest
{
    /**
     * @param  list<string>  $requires  keys of modules that must be active
     * @param  array<string, string>  $permissions  permission name => German description
     * @param  array<string, list<string>>  $startRoles  start role (name in config/stocks.php) => permissions it gets on the first setup
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $version,
        public string $provider,
        public array $requires,
        public array $permissions,
        public string $path,
        public array $startRoles = [],
    ) {}

    /**
     * Read the module.json of a module folder.
     */
    public static function fromFile(string $file): self
    {
        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data) || ! isset($data['key'], $data['name'], $data['provider'])) {
            throw new InvalidArgumentException("Invalid module manifest: {$file}");
        }

        return new self(
            key: $data['key'],
            name: $data['name'],
            description: $data['description'] ?? '',
            version: $data['version'] ?? '0.0.0',
            provider: $data['provider'],
            requires: $data['requires'] ?? [],
            permissions: $data['permissions'] ?? [],
            path: dirname($file),
            startRoles: $data['start_roles'] ?? [],
        );
    }
}
