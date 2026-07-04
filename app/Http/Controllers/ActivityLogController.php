<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    /**
     * Display a list of audit logs.
     */
    public function index(): Response
    {
        $logs = ActivityLog::with('user')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return Inertia::render('ActivityLogs/Index', [
            'logs' => $logs,
        ]);
    }
}
