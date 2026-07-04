<?php

namespace App\Services;

use App\Drivers\Libvirt\LibvirtDriver;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class USBService
{
    protected LibvirtDriver $driver;
    protected VMService $vmService;

    public function __construct(LibvirtDriver $driver, VMService $vmService)
    {
        $this->driver = $driver;
        $this->vmService = $vmService;
    }

    /**
     * Get list of all host USB devices.
     */
    public function getDevices(): array
    {
        // If local mode is active, try running lsusb
        if (env('LIBVIRT_DRIVER', 'local') === 'local') {
            try {
                $result = Process::run('lsusb');
                if ($result->exitCode() === 0) {
                    return $this->parseLsusb($result->output());
                }
            } catch (\Exception $e) {
                Log::warning("Failed to run lsusb: " . $e->getMessage() . ". Falling back to mock/cached USB list.");
            }
        }

        // Return mock devices for development
        return $this->getMockDevices();
    }

    /**
     * Parse lsusb output.
     */
    protected function parseLsusb(string $output): array
    {
        $lines = explode("\n", trim($output));
        $devices = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Match format: Bus 002 Device 004: ID 03f0:1985 HP, Inc. USB Flash Drive
            if (preg_match('/Bus\s+(\d+)\s+Device\s+(\d+):\s+ID\s+([0-9a-fA-F]+):([0-9a-fA-F]+)\s*(.*)/', $line, $matches)) {
                $bus = $matches[1];
                $deviceNum = $matches[2];
                $vendorId = strtolower($matches[3]);
                $productId = strtolower($matches[4]);
                $fullName = trim($matches[5]);

                // Split manufacturer and product name
                $parts = explode(' ', $fullName, 2);
                $manufacturer = $parts[0] ?? 'Unknown';
                $productName = $parts[1] ?? 'USB Device';
                
                // Remove trailing punctuation from manufacturer if any
                $manufacturer = rtrim($manufacturer, ',');

                $devices[] = [
                    'vendor_id' => $vendorId,
                    'product_id' => $productId,
                    'manufacturer' => $manufacturer,
                    'product_name' => $productName,
                    'bus' => $bus,
                    'device_number' => $deviceNum,
                ];
            }
        }

        return $devices;
    }

    /**
     * Get mock devices for development.
     */
    protected function getMockDevices(): array
    {
        // Return a stable list of simulated USB devices for development/mock mode
        return [
            [
                'vendor_id' => '03f0',
                'product_id' => '1985',
                'manufacturer' => 'HP',
                'product_name' => 'USB Flash Drive',
                'bus' => '002',
                'device_number' => '004',
            ],
            [
                'vendor_id' => '046d',
                'product_id' => 'c52b',
                'manufacturer' => 'Logitech',
                'product_name' => 'Unifying Receiver',
                'bus' => '001',
                'device_number' => '003',
            ],
            [
                'vendor_id' => '0930',
                'product_id' => '6545',
                'manufacturer' => 'Toshiba',
                'product_name' => 'TransMemory',
                'bus' => '001',
                'device_number' => '005',
            ],
        ];
    }

    /**
     * Get mapping of attached USBs to VMs.
     * Returns: [ "vendor_id:product_id" => [ "vm_uuid" => "...", "vm_name" => "..." ] ]
     */
    public function getAttachedDevices(): array
    {
        $driver = env('LIBVIRT_DRIVER', 'mock');
        
        if ($driver === 'mock') {
            return Cache::get('mock_attached_usbs', []);
        }

        // Live Mode: query all running VMs and inspect their XML
        $attached = [];
        try {
            $vms = $this->vmService->getVMs();
            foreach ($vms as $vm) {
                if ($vm['status'] === 'running') {
                    $xmlDesc = $this->driver->getXMLDesc($vm['uuid']);
                    $xml = @simplexml_load_string($xmlDesc);
                    if ($xml && $xml->devices && $xml->devices->hostdev) {
                        foreach ($xml->devices->hostdev as $hostdev) {
                            if ((string)$hostdev['type'] === 'usb' && isset($hostdev->source->vendor) && isset($hostdev->source->product)) {
                                $vendorId = str_replace('0x', '', strtolower((string)$hostdev->source->vendor['id']));
                                $productId = str_replace('0x', '', strtolower((string)$hostdev->source->product['id']));
                                
                                // Normalize length (4 chars, zero-padded)
                                $vendorId = str_pad($vendorId, 4, '0', STR_PAD_LEFT);
                                $productId = str_pad($productId, 4, '0', STR_PAD_LEFT);

                                $attached["$vendorId:$productId"] = [
                                    'vm_uuid' => $vm['uuid'],
                                    'vm_name' => $vm['name'],
                                ];
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to query attached USB devices from VMs: " . $e->getMessage());
        }

        return $attached;
    }

    /**
     * Attach a USB device to a running VM.
     */
    public function attach(string $vmUuid, string $vendorId, string $productId): array
    {
        $this->checkSafetyMode();

        $vm = $this->vmService->getVMDetails($vmUuid);
        if ($vm['status'] !== 'running') {
            return [
                'success' => false,
                'message' => "VM '{$vm['name']}' is not running. USB devices can only be attached to running VMs."
            ];
        }

        $driver = env('LIBVIRT_DRIVER', 'mock');
        if ($driver === 'mock') {
            $attached = Cache::get('mock_attached_usbs', []);
            $attached["$vendorId:$productId"] = [
                'vm_uuid' => $vmUuid,
                'vm_name' => $vm['name'],
            ];
            Cache::forever('mock_attached_usbs', $attached);

            $this->logActivity('usb.attach', [
                'vm_uuid' => $vmUuid,
                'vm_name' => $vm['name'],
                'vendor_id' => $vendorId,
                'product_id' => $productId,
            ]);

            return [
                'success' => true,
                'message' => "USB device successfully attached (Mock Mode) to '{$vm['name']}'."
            ];
        }

        // Real attachment on Ubuntu host
        $xmlDesc = $this->generateUsbXml($vendorId, $productId);
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }
        $filePath = $tmpDir . '/usb_' . $vendorId . '_' . $productId . '_' . time() . '.xml';
        file_put_contents($filePath, $xmlDesc);

        try {
            $vmName = escapeshellarg($vm['name']);
            $xmlPath = escapeshellarg($filePath);
            $result = Process::run("virsh attach-device {$vmName} {$xmlPath} --live");

            if ($result->exitCode() !== 0) {
                return [
                    'success' => false,
                    'message' => "Failed to attach USB: " . $result->errorOutput()
                ];
            }

            $this->logActivity('usb.attach', [
                'vm_uuid' => $vmUuid,
                'vm_name' => $vm['name'],
                'vendor_id' => $vendorId,
                'product_id' => $productId,
            ]);

            return [
                'success' => true,
                'message' => "USB device successfully attached to VM '{$vm['name']}'."
            ];
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    /**
     * Detach a USB device from a running VM.
     */
    public function detach(string $vmUuid, string $vendorId, string $productId): array
    {
        $this->checkSafetyMode();

        $vm = $this->vmService->getVMDetails($vmUuid);
        
        $driver = env('LIBVIRT_DRIVER', 'mock');
        if ($driver === 'mock') {
            $attached = Cache::get('mock_attached_usbs', []);
            unset($attached["$vendorId:$productId"]);
            Cache::forever('mock_attached_usbs', $attached);

            $this->logActivity('usb.detach', [
                'vm_uuid' => $vmUuid,
                'vm_name' => $vm['name'],
                'vendor_id' => $vendorId,
                'product_id' => $productId,
            ]);

            return [
                'success' => true,
                'message' => "USB device successfully detached (Mock Mode) from '{$vm['name']}'."
            ];
        }

        // Real detachment on Ubuntu host
        $xmlDesc = $this->generateUsbXml($vendorId, $productId);
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0777, true);
        }
        $filePath = $tmpDir . '/usb_' . $vendorId . '_' . $productId . '_' . time() . '.xml';
        file_put_contents($filePath, $xmlDesc);

        try {
            $vmName = escapeshellarg($vm['name']);
            $xmlPath = escapeshellarg($filePath);
            $result = Process::run("virsh detach-device {$vmName} {$xmlPath} --live");

            if ($result->exitCode() !== 0) {
                return [
                    'success' => false,
                    'message' => "Failed to detach USB: " . $result->errorOutput()
                ];
            }

            $this->logActivity('usb.detach', [
                'vm_uuid' => $vmUuid,
                'vm_name' => $vm['name'],
                'vendor_id' => $vendorId,
                'product_id' => $productId,
            ]);

            return [
                'success' => true,
                'message' => "USB device successfully detached from VM '{$vm['name']}'."
            ];
        } finally {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    /**
     * Generate USB device XML dynamically.
     */
    protected function generateUsbXml(string $vendorId, string $productId): string
    {
        // Hex values should have 0x prefix for virsh XML config
        $vendorHex = '0x' . ltrim(strtolower($vendorId), '0x');
        $productHex = '0x' . ltrim(strtolower($productId), '0x');

        return <<<XML
<hostdev mode='subsystem' type='usb' managed='yes'>
    <source>
        <vendor id='{$vendorHex}'/>
        <product id='{$productHex}'/>
    </source>
</hostdev>
XML;
    }

    /**
     * Log user activity to database.
     */
    protected function logActivity(string $action, array $details): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'details' => $details,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Check if safety mode blocks this command.
     */
    protected function checkSafetyMode(): void
    {
        if (config('hypervisor.mode', 'readonly') === 'readonly') {
            throw new \App\Exceptions\DestructiveCommandBlockedException();
        }
    }
}
