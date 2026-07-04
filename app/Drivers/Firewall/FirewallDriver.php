<?php

namespace App\Drivers\Firewall;

use App\Models\PortMapping;

interface FirewallDriver
{
    /**
     * Apply a NAT port forwarding rule.
     * 
     * @param PortMapping $mapping
     * @return void
     */
    public function applyPortForward(PortMapping $mapping): void;

    /**
     * Remove a NAT port forwarding rule.
     * 
     * @param PortMapping $mapping
     * @return void
     */
    public function removePortForward(PortMapping $mapping): void;
}
