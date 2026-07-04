<?php

namespace App\Http\Controllers;

use App\Services\VMService;
use App\Models\RdpVncMapping;
use App\Models\PublishedApplication;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class VMController extends Controller
{
    protected VMService $vmService;

    public function __construct(VMService $vmService)
    {
        $this->vmService = $vmService;
    }

    /**
     * Display a specific VM details page.
     */
    public function show(string $uuid): Response
    {
        $vm = $this->vmService->getVMDetails($uuid);
        
        // Fetch VNC/RDP mappings and published apps for this VM
        $rdpMappings = RdpVncMapping::where('vm_uuid', $uuid)->get();
        $publishedApps = PublishedApplication::where('vm_uuid', $uuid)->get();

        return Inertia::render('VMs/Show', [
            'vm' => $vm,
            'rdpMappings' => $rdpMappings,
            'publishedApps' => $publishedApps,
        ]);
    }

    /**
     * Start VM.
     */
    public function start(string $uuid): RedirectResponse
    {
        $this->vmService->startVM($uuid);
        return back()->with('success', 'VM started successfully.');
    }

    /**
     * Stop VM (Graceful).
     */
    public function stop(string $uuid): RedirectResponse
    {
        $this->vmService->stopVM($uuid, false);
        return back()->with('success', 'Shutdown command sent to VM.');
    }

    /**
     * Force Stop VM.
     */
    public function forceStop(string $uuid): RedirectResponse
    {
        $this->vmService->stopVM($uuid, true);
        return back()->with('success', 'VM force stopped.');
    }

    /**
     * Reboot VM.
     */
    public function reboot(string $uuid): RedirectResponse
    {
        $this->vmService->rebootVM($uuid);
        return back()->with('success', 'VM rebooted.');
    }

    /**
     * Suspend VM.
     */
    public function suspend(string $uuid): RedirectResponse
    {
        $this->vmService->suspendVM($uuid);
        return back()->with('success', 'VM suspended.');
    }

    /**
     * Resume VM.
     */
    public function resume(string $uuid): RedirectResponse
    {
        $this->vmService->resumeVM($uuid);
        return back()->with('success', 'VM resumed.');
    }

    /**
     * Update VM Metadata (Tags and Notes).
     */
    public function updateMetadata(Request $request, string $uuid): RedirectResponse
    {
        $validated = $request->validate([
            'tags' => 'array',
            'tags.*' => 'string|max:30',
            'notes' => 'string|nullable',
        ]);

        $this->vmService->updateMetadata($uuid, $validated['tags'] ?? [], $validated['notes'] ?? '');

        return back()->with('success', 'VM metadata updated successfully.');
    }

    /**
     * Store a newly created virtual machine.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|alpha_dash|max:50',
            'vcpus' => 'required|integer|between:1,32',
            'memory_mb' => 'required|integer|between:512,131072',
            'disk_gb' => 'required|integer|between:5,2000',
            'boot_type' => 'required|in:bios,uefi',
            'machine_type' => 'required|in:pc-q35-6.2,i440fx',
            'disk_bus' => 'required|in:virtio,sata,scsi,ide',
            'network_bridge' => 'required|string|max:50',
            'network_model' => 'required|in:virtio,e1000,rtl8139',
            'iso_volume' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'usb_controller' => 'boolean',
            'start_after_created' => 'boolean'
        ]);

        $uuid = $this->vmService->createVM($validated);

        if ($request->boolean('start_after_created')) {
            try {
                $this->vmService->startVM($uuid);
            } catch (\Exception $e) {
                return redirect()->route('dashboard')->with('success', 'VM created successfully, but auto-boot failed: ' . $e->getMessage());
            }
        }

        return redirect()->route('dashboard')->with('success', 'VM created successfully.');
    }

    /**
     * API Endpoint to retrieve real-time stats for graphing.
     */
    public function getStats(string $uuid): JsonResponse
    {
        try {
            $stats = $this->vmService->getVMStats($uuid);
            return response()->json($stats);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get the execution plan preview for a VM lifecycle action.
     */
    public function getExecutionPlan(string $uuid, string $action): JsonResponse
    {
        try {
            $plan = $this->vmService->getExecutionPlan($uuid, $action);
            return response()->json($plan);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
