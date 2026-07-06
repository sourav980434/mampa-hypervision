<?php

namespace App\Services\Firewall;

class FirewallRule
{
    public string $table;
    public string $chain;
    public array $parameters;

    public function __construct(string $table, string $chain, array $parameters)
    {
        $this->table = $table;
        $this->chain = $chain;
        $this->parameters = $parameters;
    }

    /**
     * Get the full command string for the given action (A, I, D, C, etc.).
     */
    public function getCommand(string $action): string
    {
        $cmd = "sudo iptables";
        if ($this->table !== 'filter') {
            $cmd .= " -t {$this->table}";
        }
        $cmd .= " -{$action} {$this->chain}";
        $cmd .= " " . implode(' ', $this->parameters);
        return $cmd;
    }
}
