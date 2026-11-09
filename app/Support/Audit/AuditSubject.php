<?php

namespace App\Support\Audit;

/**
 * An audited model that can be shown in the audit log.
 *
 * Register the model class with AuditSubjects::register() (the core in the AppServiceProvider,
 * modules in their service provider), otherwise its audits are listed without a readable name.
 */
interface AuditSubject
{
    /**
     * German name of the model type, e.g. "Konto".
     */
    public static function auditTypeLabel(): string;

    /**
     * Display name of a record, built from its attributes. The record may already be deleted.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function auditName(array $attributes): string;

    /**
     * German labels of the audited fields. Fields without a label are shown with their column name.
     *
     * @return array<string, string>
     */
    public static function auditFieldLabels(): array;
}
