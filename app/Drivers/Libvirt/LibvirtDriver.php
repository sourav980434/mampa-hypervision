<?php

namespace App\Drivers\Libvirt;

interface LibvirtDriver
{
    /**
     * Get a list of all VMs.
     * 
     * @return array Array of VMs, each with uuid, name, state.
     */
    public function getVMs(): array;

    /**
     * Get detailed information for a specific VM.
     * 
     * @param string $uuid
     * @return array
     */
    public function getVMDetails(string $uuid): array;

    /**
     * Start a VM.
     * 
     * @param string $uuid
     * @return void
     */
    public function startVM(string $uuid): void;

    /**
     * Stop a VM gracefully or forcefully.
     * 
     * @param string $uuid
     * @param bool $force
     * @return void
     */
    public function stopVM(string $uuid, bool $force = false): void;

    /**
     * Reboot a VM.
     * 
     * @param string $uuid
     * @return void
     */
    public function rebootVM(string $uuid): void;

    /**
     * Suspend a VM.
     * 
     * @param string $uuid
     * @return void
     */
    public function suspendVM(string $uuid): void;

    /**
     * Resume a suspended VM.
     * 
     * @param string $uuid
     * @return void
     */
    public function resumeVM(string $uuid): void;

    /**
     * Get resource utilization stats (CPU, Memory, Disk IO) for a VM.
     * 
     * @param string $uuid
     * @return array
     */
    public function getVMStats(string $uuid): array;

    /**
     * Get the XML configuration of a VM.
     * 
     * @param string $uuid
     * @return string
     */
    public function getXMLDesc(string $uuid): string;

    /**
     * Create a new VM from its XML definition.
     * 
     * @param string $xmlDesc
     * @return string Returns the UUID of the created VM.
     */
    public function createVMFromXML(string $xmlDesc): string;

    /**
     * Undefine (delete) a VM configuration.
     * 
     * @param string $uuid
     * @return void
     */
    public function undefineVM(string $uuid): void;

    /**
     * Get a list of storage pools.
     * 
     * @return array
     */
    public function getStoragePools(): array;

    /**
     * Get a list of available ISO images.
     * 
     * @return array
     */
    public function getISOs(): array;
}
