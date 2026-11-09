<?php

namespace App\Support\Audit;

/**
 * Registry of the models that are shown in the audit log.
 *
 * Filled once while the application boots (service providers), so it holds no request state.
 */
class AuditSubjects
{
    /**
     * Registered model classes by their short key (class basename).
     *
     * @var array<string, class-string<AuditSubject>>
     */
    private array $classes = [];

    /**
     * Register an audited model.
     *
     * @param  class-string<AuditSubject>  $class
     */
    public function register(string $class): void
    {
        $this->classes[class_basename($class)] = $class;
    }

    /**
     * @return array<string, class-string<AuditSubject>>
     */
    public function all(): array
    {
        return $this->classes;
    }

    /**
     * Get the class of a short key, null for unknown keys.
     *
     * @return class-string<AuditSubject>|null
     */
    public function classOf(string $key): ?string
    {
        return $this->classes[$key] ?? null;
    }

    /**
     * Get the short key of a model class.
     */
    public function keyOf(string $class): string
    {
        return array_search($class, $this->classes, true) ?: class_basename($class);
    }
}
