<?php

namespace App\Http\Controllers;

use App\Services\VMService;
use App\Models\PortMapping;
use App\Models\ActivityLog;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    protected VMService $vmService;

    public function __construct(VMService $vmService)
    {
        $this->vmService = $vmService;
    }

    /**
     * Display the hypervisor dashboard.
     */
    public function index(): Response
    {
        $vms = $this->vmService->getVMs();
        
        $totalVms = count($vms);
        $runningVms = count(array_filter($vms, fn($vm) => $vm['status'] === 'running'));
        $pausedVms = count(array_filter($vms, fn($vm) => $vm['status'] === 'paused'));
        
        $totalCpus = array_sum(array_column($vms, 'vcpus'));
        $totalMemory = array_sum(array_column($vms, 'memory_mb')) / 1024; // in GB

        // Fetch recent activity logs with user details
        $recentLogs = ActivityLog::with('user')
            ->orderBy('id', 'desc')
            ->limit(7)
            ->get();

        // Fetch counts for other metrics
        $activePortsCount = PortMapping::where('status', 'active')->count();

        return Inertia::render('Dashboard', [
            'hostStats' => [
                'total_vms' => $totalVms,
                'running_vms' => $runningVms,
                'paused_vms' => $pausedVms,
                'total_cpus_allocated' => $totalCpus,
                'total_memory_allocated_gb' => round($totalMemory, 1),
                'host_cpu_cores' => 12,
                'host_memory_total_gb' => 32,
                'host_disk_total_gb' => 1000,
                'host_disk_used_gb' => 245,
            ],
            'activePortsCount' => $activePortsCount,
            'recentLogs' => $recentLogs,
        ]);
    }
}
