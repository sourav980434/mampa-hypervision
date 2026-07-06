<?php

namespace App\Drivers\Firewall;

use App\Models\PortMapping;

interface FirewallDriver
{
    /**
     * Apply a NAT port forwarding rule.
     */
    public function applyPortForward(PortMapping $mapping): void;

    /**
     * Remove a NAT port forwarding rule.
     */
    public function removePortForward(PortMapping $mapping): void;

    public function ensureDNATRule(PortMapping $mapping): void;

    public function ensureForwardInboundRule(PortMapping $mapping): void;

    public function ensureForwardReturnRule(PortMapping $mapping): void;

    public function ensureMasqueradeRule(PortMapping $mapping): void;
}
