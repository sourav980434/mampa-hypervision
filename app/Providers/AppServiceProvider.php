<?php

namespace App\Providers;

use App\Drivers\Libvirt\LibvirtDriver;
use App\Drivers\Libvirt\MockLibvirtDriver;
use App\Drivers\Libvirt\LocalLibvirtDriver;
use App\Drivers\Firewall\FirewallDriver;
use App\Drivers\Firewall\MockFirewallDriver;
use App\Drivers\Firewall\LocalIptablesFirewallDriver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LibvirtDriver::class, function ($app) {
            $driver = env('LIBVIRT_DRIVER', 'mock');
            if ($driver === 'local') {
                try {
                    $test = Process::run('virsh --version');
                    if ($test->exitCode() === 0) {
                        return new LocalLibvirtDriver();
                    }
                } catch (\Exception $e) {
                    // Failures to execute or check fallback to mock
                }
                Log::warning("LocalLibvirtDriver requested in .env but 'virsh' is not available. Falling back to MockLibvirtDriver.");
            }
            return new MockLibvirtDriver();
        });

        $this->app->singleton(FirewallDriver::class, function ($app) {
            $driver = env('FIREWALL_DRIVER', 'mock');
            if ($driver === 'iptables' || $driver === 'local_iptables') {
                try {
                    $paths = ['iptables', '/sbin/iptables', '/usr/sbin/iptables', '/usr/local/sbin/iptables'];
                    foreach ($paths as $path) {
                        $test = Process::run("{$path} --version");
                        if ($test->exitCode() === 0) {
                            return new LocalIptablesFirewallDriver();
                        }
                    }
                } catch (\Exception $e) {
                    // Failures to execute or check fallback to mock
                }
                Log::warning("LocalIptablesFirewallDriver requested in .env but 'iptables' is not available. Falling back to MockFirewallDriver.");
            }
            return new MockFirewallDriver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
