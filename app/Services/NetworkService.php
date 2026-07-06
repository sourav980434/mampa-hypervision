<?php

namespace App\Services;

use App\Drivers\Firewall\FirewallDriver;
use App\Models\PortMapping;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class NetworkService
{
    protected FirewallDriver $firewall;

    public function __construct(FirewallDriver $firewall)
    {
        $this->firewall = $firewall;
    }

    /**
     * Get all port mappings.
     */
    public function getMappings(): array
    {
        return PortMapping::orderBy('public_port')->get()->toArray();
    }

    /**
     * Create a new port mapping.
     */
    public function createMapping(array $data): PortMapping
    {
        $mapping = PortMapping::create([
            'public_port' => $data['public_port'],
            'internal_ip' => $data['internal_ip'],
            'internal_port' => $data['internal_port'],
            'protocol' => $data['protocol'] ?? 'tcp',
            'description' => $data['description'] ?? null,
            'status' => 'active',
        ]);

        // Apply rules via firewall driver
        $this->firewall->applyPortForward($mapping);

        $this->logActivity('port.create', [
            'id' => $mapping->id,
            'public_port' => $mapping->public_port,
            'internal' => "{$mapping->internal_ip}:{$mapping->internal_port}",
            'protocol' => $mapping->protocol,
        ]);

        return $mapping;
    }

    /**
     * Toggle status of a port mapping (active/inactive).
     */
    public function toggleMapping(int $id): PortMapping
    {
        $mapping = PortMapping::findOrFail($id);

        if ($mapping->status === 'active') {
            $mapping->status = 'inactive';
            $mapping->save();
            $this->firewall->removePortForward($mapping);

            $this->logActivity('port.deactivate', [
                'id' => $mapping->id,
                'public_port' => $mapping->public_port,
            ]);
        } else {
            $mapping->status = 'active';
            $mapping->save();
            $this->firewall->applyPortForward($mapping);

            $this->logActivity('port.activate', [
                'id' => $mapping->id,
                'public_port' => $mapping->public_port,
            ]);
        }

        return $mapping;
    }

    /**
     * Delete a port mapping.
     */
    public function deleteMapping(int $id): void
    {
        $mapping = PortMapping::findOrFail($id);

        if ($mapping->status === 'active') {
            $this->firewall->removePortForward($mapping);
        }

        $this->logActivity('port.delete', [
            'id' => $mapping->id,
            'public_port' => $mapping->public_port,
            'internal' => "{$mapping->internal_ip}:{$mapping->internal_port}",
        ]);

        $mapping->delete();
    }

    /**
     * Generate an execution plan for a firewall rule modification.
     */
    public function getExecutionPlan(string $action, array $data = []): array
    {
        $mode = config('hypervisor.mode', 'readonly');
        $plan = [
            'action' => $action,
            'mode' => $mode,
            'risk_level' => 'MEDIUM',
        ];

        switch ($action) {
            case 'create':
                $proto = $data['protocol'] ?? 'tcp';
                $pubPort = (int) ($data['public_port'] ?? 0);
                $intIp = $data['internal_ip'] ?? '0.0.0.0';
                $intPort = (int) ($data['internal_port'] ?? 0);

                $plan['command'] = implode("\n", [
                    "sudo iptables -t nat -A PREROUTING -p {$proto} --dport {$pubPort} -j DNAT --to-destination {$intIp}:{$intPort}",
                    "sudo iptables -A FORWARD -p {$proto} -d {$intIp} --dport {$intPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT",
                    "sudo iptables -t nat -A POSTROUTING -p {$proto} -d {$intIp} --dport {$intPort} -j MASQUERADE"
                ]);
                $plan['expected_result'] = "Incoming {$proto} traffic on public port {$pubPort} will be routed to {$intIp}:{$intPort}.";
                $plan['rollback_option'] = "The rule can be deleted or set to inactive at any time.";
                break;

            case 'toggle':
                $mapping = PortMapping::findOrFail($data['id']);
                $proto = $mapping->protocol;
                $pubPort = (int) $mapping->public_port;
                $intIp = $mapping->internal_ip;
                $intPort = (int) $mapping->internal_port;

                if ($mapping->status === 'active') {
                    // It will be deactivated (removing rules)
                    $plan['command'] = implode("\n", [
                        "sudo iptables -t nat -D PREROUTING -p {$proto} --dport {$pubPort} -j DNAT --to-destination {$intIp}:{$intPort}",
                        "sudo iptables -D FORWARD -p {$proto} -d {$intIp} --dport {$intPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT",
                        "sudo iptables -t nat -D POSTROUTING -p {$proto} -d {$intIp} --dport {$intPort} -j MASQUERADE"
                    ]);
                    $plan['expected_result'] = "NAT routing on public port {$pubPort} will be deactivated and traffic blocked.";
                    $plan['rollback_option'] = "Toggle status back to active to re-apply the routing.";
                } else {
                    // It will be activated (applying rules)
                    $plan['command'] = implode("\n", [
                        "sudo iptables -t nat -A PREROUTING -p {$proto} --dport {$pubPort} -j DNAT --to-destination {$intIp}:{$intPort}",
                        "sudo iptables -A FORWARD -p {$proto} -d {$intIp} --dport {$intPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT",
                        "sudo iptables -t nat -A POSTROUTING -p {$proto} -d {$intIp} --dport {$intPort} -j MASQUERADE"
                    ]);
                    $plan['expected_result'] = "Incoming traffic on public port {$pubPort} will be enabled and routed to {$intIp}:{$intPort}.";
                    $plan['rollback_option'] = "Toggle status back to inactive to remove the routing.";
                }
                break;

            case 'delete':
                $mapping = PortMapping::findOrFail($data['id']);
                $proto = $mapping->protocol;
                $pubPort = (int) $mapping->public_port;
                $intIp = $mapping->internal_ip;
                $intPort = (int) $mapping->internal_port;

                if ($mapping->status === 'active') {
                    $plan['command'] = implode("\n", [
                        "sudo iptables -t nat -D PREROUTING -p {$proto} --dport {$pubPort} -j DNAT --to-destination {$intIp}:{$intPort}",
                        "sudo iptables -D FORWARD -p {$proto} -d {$intIp} --dport {$intPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT",
                        "sudo iptables -t nat -D POSTROUTING -p {$proto} -d {$intIp} --dport {$intPort} -j MASQUERADE"
                    ]);
                    $plan['expected_result'] = "Routing rules are deleted from the host and database entry is removed.";
                    $plan['rollback_option'] = "Re-create the rule manually in the panel.";
                } else {
                    $plan['command'] = "# No firewall commands (mapping is already inactive)";
                    $plan['expected_result'] = "Database mapping entry is removed. No firewall changes needed.";
                    $plan['rollback_option'] = "Re-create the rule manually in the panel.";
                }
                break;

            case 'reapply':
                $activeMappings = PortMapping::where('status', 'active')->get();
                $cmds = [];
                foreach ($activeMappings as $m) {
                    $proto = $m->protocol;
                    $pubPort = (int) $m->public_port;
                    $intIp = $m->internal_ip;
                    $intPort = (int) $m->internal_port;

                    $cmds[] = "sudo iptables -t nat -A PREROUTING -p {$proto} --dport {$pubPort} -j DNAT --to-destination {$intIp}:{$intPort}";
                    $cmds[] = "sudo iptables -A FORWARD -p {$proto} -d {$intIp} --dport {$intPort} -m state --state NEW,ESTABLISHED,RELATED -j ACCEPT";
                    $cmds[] = "sudo iptables -t nat -A POSTROUTING -p {$proto} -d {$intIp} --dport {$intPort} -j MASQUERADE";
                }

                $plan['command'] = empty($cmds) ? "# No active mappings to re-apply" : implode("\n", $cmds);
                $plan['expected_result'] = "All active port forwarding rules (total: " . count($activeMappings) . ") will be re-applied to iptables.";
                $plan['rollback_option'] = "Manually deactivate individual rules or delete them.";
                break;

            default:
                throw new \InvalidArgumentException("Invalid firewall action: {$action}");
        }

        return $plan;
    }

    /**
     * Re-apply all active port mappings (useful for reboot recovery).
     */
    public function reapplyAll(): void
    {
        $activeMappings = PortMapping::where('status', 'active')->get();
        foreach ($activeMappings as $mapping) {
            $this->firewall->removePortForward($mapping);
            $this->firewall->applyPortForward($mapping);
        }

        $this->logActivity('port.reapply_all', [
            'count' => count($activeMappings)
        ]);
    }

    /**
     * Test connectivity to the destination internal IP:port.
     */
    public function testConnectivity(int $id): array
    {
        $mapping = PortMapping::findOrFail($id);
        $ip = $mapping->internal_ip;
        $port = $mapping->internal_port;

        $this->logActivity('port.test', [
            'id' => $mapping->id,
            'destination' => "{$ip}:{$port}"
        ]);

        try {
            $res = \Illuminate\Support\Facades\Process::run("nc -vz -w 2 " . escapeshellarg($ip) . " " . escapeshellarg($port));
            if ($res->exitCode() === 0) {
                return [
                    'success' => true,
                    'message' => "Firewall OK"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "VM service is not listening."
                ];
            }
        } catch (\Throwable $e) {
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen($ip, $port, $errno, $errstr, 1.5);
            if ($fp) {
                fclose($fp);
                return [
                    'success' => true,
                    'message' => "Firewall OK"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "VM service is not listening."
                ];
            }
        }
    }

    /**
     * Log user activity.
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
}
