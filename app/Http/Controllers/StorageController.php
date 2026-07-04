<?php

namespace App\Http\Controllers;

use App\Services\VMService;
use Illuminate\Http\JsonResponse;

class StorageController extends Controller
{
    protected VMService $vmService;

    public function __construct(VMService $vmService)
    {
        $this->vmService = $vmService;
    }

    /**
     * Get list of storage pools.
     */
    public function getPools(): JsonResponse
    {
        try {
            $pools = $this->vmService->getStoragePools();
            return response()->json($pools);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get list of available ISO files.
     */
    public function getISOs(): JsonResponse
    {
        try {
            $isos = $this->vmService->getISOs();
            return response()->json($isos);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
