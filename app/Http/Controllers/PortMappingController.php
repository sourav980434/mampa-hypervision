<?php

namespace App\Http\Controllers;

use App\Services\NetworkService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class PortMappingController extends Controller
{
    protected NetworkService $networkService;

    public function __construct(NetworkService $networkService)
    {
        $this->networkService = $networkService;
    }

    /**
     * Display the port mappings list.
     */
    public function index(): Response
    {
        $mappings = $this->networkService->getMappings();

        return Inertia::render('PortForwarding/Index', [
            'mappings' => $mappings,
        ]);
    }

    /**
     * Store a new port mapping.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'public_port' => 'required|integer|between:1024,65535|unique:port_mappings,public_port',
            'internal_ip' => 'required|ip',
            'internal_port' => 'required|integer|between:1,65535',
            'protocol' => 'required|in:tcp,udp',
            'description' => 'nullable|string|max:255',
        ]);

        $this->networkService->createMapping($validated);

        return redirect()->route('port-forwarding.index')->with('success', 'Port forwarding rule created.');
    }

    /**
     * Toggle active status of a mapping.
     */
    public function toggle(int $id): RedirectResponse
    {
        $this->networkService->toggleMapping($id);
        return back()->with('success', 'Port forwarding status toggled.');
    }

    /**
     * Delete a port mapping.
     */
    public function destroy(int $id): RedirectResponse
    {
        $this->networkService->deleteMapping($id);
        return redirect()->route('port-forwarding.index')->with('success', 'Port forwarding rule deleted.');
    }

    /**
     * Get execution plan preview for a firewall rule modification.
     */
    public function getExecutionPlan(Request $request, string $action): JsonResponse
    {
        try {
            $data = $request->all();
            $plan = $this->networkService->getExecutionPlan($action, $data);
            return response()->json($plan);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Re-apply all active port forwarding rules.
     */
    public function reapply(): RedirectResponse
    {
        try {
            $this->networkService->reapplyAll();
            return redirect()->route('port-forwarding.index')->with('success', 'All active port forwarding rules re-applied to host firewall.');
        } catch (\Exception $e) {
            return redirect()->route('port-forwarding.index')->with('error', $e->getMessage());
        }
    }

    /**
     * Run connectivity TCP check.
     */
    public function test(int $id): JsonResponse
    {
        try {
            $result = $this->networkService->testConnectivity($id);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
