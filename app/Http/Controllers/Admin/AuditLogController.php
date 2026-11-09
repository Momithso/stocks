<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditLogFormatter;
use App\Support\Audit\AuditSubjects;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use OwenIt\Auditing\Models\Audit;

/**
 * The audit log: who changed what and when.
 */
class AuditLogController extends Controller
{
    /**
     * Display a page of the audit log with filters.
     */
    public function index(Request $request, AuditSubjects $subjects, AuditLogFormatter $formatter): Response
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string'],
            'subject_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $audits = Audit::query()
            ->with('user')
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('user_type', User::class)->where('user_id', $id))
            ->when($subjects->classOf($filters['subject_type'] ?? ''), fn ($query, $class) => $query->where('auditable_type', $class))
            ->when($filters['subject_id'] ?? null, fn ($query, $id) => $query->where('auditable_id', $id))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('event', $action))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $audits->setCollection($formatter->format($audits->getCollection()));

        return Inertia::render('Admin/AuditLog', [
            'audits' => $audits,
            'filters' => $filters,
            'users' => User::orderBy('firstname')->orderBy('lastname')->get(['id', 'firstname', 'lastname']),
            'subjectTypes' => $formatter->subjectTypeOptions(),
            'actions' => AuditLogFormatter::actionOptions(),
        ]);
    }
}
