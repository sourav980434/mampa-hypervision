<?php

namespace App\Http\Controllers;

use App\Services\USBService;
use App\Services\VMService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class USBController extends Controller
{
    protected USBService $usbService;
    protected VMService $vmService;

    public function __construct(USBService $usbService, VMService $vmService)
    {
        $this->usbService = $usbService;
        $this->vmService = $vmService;
    }

    /**
     * Display the USB Devices page.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('USBDevices/Index');
    }

    /**
     * API to get all host USB devices.
     */
    public function getDevices(): JsonResponse
    {
        try {
            $devices = $this->usbService->getDevices();
            return response()->json($devices);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * API to get current attached state of all USB devices.
     */
    public function getAttached(): JsonResponse
    {
        try {
            $attached = $this->usbService->getAttachedDevices();
            return response()->json($attached);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Attach a USB device to a virtual machine.
     */
    public function attach(Request $request): JsonResponse
    {
        // Security check: Only admins can attach
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admin users can attach USB devices.'
            ], 403);
        }

        $validated = $request->validate([
            'vm_uuid' => 'required|string',
            'vendor_id' => 'required|string',
            'product_id' => 'required|string',
        ]);

        try {
            $result = $this->usbService->attach(
                $validated['vm_uuid'],
                $validated['vendor_id'],
                $validated['product_id']
            );

            if (!$result['success']) {
                return response()->json($result, 400);
            }

            return response()->json($result);
        } catch (\App\Exceptions\DestructiveCommandBlockedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during USB attachment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detach a USB device from a virtual machine.
     */
    public function detach(Request $request): JsonResponse
    {
        // Security check: Only admins can detach
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admin users can detach USB devices.'
            ], 403);
        }

        $validated = $request->validate([
            'vm_uuid' => 'required|string',
            'vendor_id' => 'required|string',
            'product_id' => 'required|string',
        ]);

        try {
            $result = $this->usbService->detach(
                $validated['vm_uuid'],
                $validated['vendor_id'],
                $validated['product_id']
            );

            if (!$result['success']) {
                return response()->json($result, 400);
            }

            return response()->json($result);
        } catch (\App\Exceptions\DestructiveCommandBlockedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during USB detachment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get list of USB storage partitions on host.
     */
    public function getStorage(): JsonResponse
    {
        try {
            $devices = $this->usbService->getStorageDevices();
            return response()->json($devices);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mount a USB storage partition.
     */
    public function mount(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admin users can mount USB partitions.'
            ], 403);
        }

        $validated = $request->validate([
            'device' => 'required|string',
        ]);

        try {
            $result = $this->usbService->mountStorage($validated['device']);
            if (!$result['success']) {
                return response()->json($result, 400);
            }
            return response()->json($result);
        } catch (\App\Exceptions\DestructiveCommandBlockedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Unmount a USB storage partition.
     */
    public function unmount(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only admin users can unmount USB partitions.'
            ], 403);
        }

        $validated = $request->validate([
            'device' => 'required|string',
        ]);

        try {
            $result = $this->usbService->unmountStorage($validated['device']);
            if (!$result['success']) {
                return response()->json($result, 400);
            }
            return response()->json($result);
        } catch (\App\Exceptions\DestructiveCommandBlockedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ], 500);
        }
    }
}
