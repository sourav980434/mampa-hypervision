<script setup>
import { ref, computed, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth.user);
const vms = computed(() => page.props.vms || []);

const isTreeExpanded = ref(true);

const flashSuccess = ref(page.props.flash?.success);
const flashError = ref(page.props.flash?.error);

watch(() => page.props.flash, (newFlash) => {
    flashSuccess.value = newFlash?.success;
    flashError.value = newFlash?.error;
}, { deep: true });

const getStatusColor = (status) => {
    switch (status) {
        case 'running':
            return 'bg-emerald-500';
        case 'paused':
            return 'bg-amber-500';
        default:
            return 'bg-rose-500';
    }
};

const getStatusText = (status) => {
    return status.charAt(0).toUpperCase() + status.slice(1);
};
</script>

<template>
    <div class="min-h-screen bg-[#111214] text-[#e0e0e2] font-sans flex flex-col">
        <!-- Top Banner Header -->
        <header class="bg-[#1c1d22] border-b border-[#2c2d30] h-12 flex items-center justify-between px-4 shrink-0 select-none z-10">
            <!-- Brand Logo -->
            <div class="flex items-center space-x-3">
                <Link href="/dashboard" class="flex items-center space-x-2">
                    <span class="bg-[#e57300] text-black font-extrabold px-2 py-0.5 rounded text-sm tracking-wider uppercase">Mampa</span>
                    <span class="font-bold text-sm tracking-wide text-white">HYPERVISOR</span>
                </Link>
                <span class="text-xs text-gray-500 border-l border-gray-700 pl-3">v1.2.0</span>
            </div>

            <!-- Server Global Status Overview -->
            <div class="hidden md:flex items-center space-x-6 text-xs text-gray-400">
                <div class="flex items-center space-x-2">
                    <span>CPU:</span>
                    <div class="w-20 bg-gray-800 h-2 rounded overflow-hidden">
                        <div class="bg-[#e57300] h-full" style="width: 14%"></div>
                    </div>
                    <span class="text-white font-mono">14.2%</span>
                </div>
                <div class="flex items-center space-x-2">
                    <span>RAM:</span>
                    <div class="w-20 bg-gray-800 h-2 rounded overflow-hidden">
                        <div class="bg-[#e57300] h-full" style="width: 48%"></div>
                    </div>
                    <span class="text-white font-mono">4.8 GB / 16 GB</span>
                </div>
            </div>

            <!-- User Session Details -->
            <div class="flex items-center space-x-4">
                <div class="flex items-center space-x-2">
                    <span class="text-xs text-gray-400 font-mono">{{ user.name }}</span>
                    <span class="border border-[#e57300]/40 text-[#e57300] px-1.5 py-0.5 rounded text-[10px] font-mono uppercase bg-[#e57300]/10">
                        {{ user.role }}
                    </span>
                </div>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="text-xs text-gray-400 hover:text-white hover:bg-gray-800 px-2.5 py-1 rounded transition border border-[#2c2d30]"
                >
                    Logout
                </Link>
            </div>
        </header>

        <!-- Main Body Wrapper -->
        <div class="flex flex-1 overflow-hidden">
            <!-- Sidebar Navigation & Resource Tree -->
            <aside class="w-64 bg-[#1c1d22] border-r border-[#2c2d30] flex flex-col shrink-0 select-none">
                <!-- Navigation Sections -->
                <nav class="p-3 space-y-1 text-xs">
                    <!-- Datacenter Header -->
                    <div class="px-3 py-1.5 text-[10px] font-bold text-gray-500 uppercase tracking-wider select-none">
                        Datacenter
                    </div>

                    <!-- Dashboard -->
                    <Link
                        href="/dashboard"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-300 hover:text-white hover:bg-[#25262c] transition"
                        :class="{ 'bg-[#25262c] text-white border-l-2 border-[#e57300]': $page.url === '/dashboard' }"
                    >
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM14 16a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 01-2-2v-4z" />
                        </svg>
                        <span class="font-medium">Dashboard</span>
                    </Link>

                    <!-- Virtual Machines -->
                    <Link
                        href="/dashboard"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-300 hover:text-white hover:bg-[#25262c] transition"
                    >
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span class="font-medium">Virtual Machines</span>
                    </Link>

                    <!-- Storage -->
                    <div
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-500 cursor-not-allowed select-none opacity-60"
                    >
                        <svg class="w-4 h-4 text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.58 4 8 4s8-1.79 8-4M4 7c0-2.21 3.58-4 8-4s8 1.79 8 4m0 5c0 2.21-3.58 4-8 4s-8-1.79-8-4" />
                        </svg>
                        <span class="font-medium">Storage</span>
                    </div>

                    <!-- Port Mapping -->
                    <Link
                        href="/port-forwarding"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-300 hover:text-white hover:bg-[#25262c] transition"
                        :class="{ 'bg-[#25262c] text-white border-l-2 border-[#e57300]': $page.url.startsWith('/port-forwarding') }"
                    >
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                        </svg>
                        <span class="font-medium">Port Mapping</span>
                    </Link>

                    <!-- Published Apps -->
                    <div
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-500 cursor-not-allowed select-none opacity-60"
                    >
                        <svg class="w-4 h-4 text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <span class="font-medium">Published Apps</span>
                    </div>

                    <!-- USB Devices -->
                    <Link
                        href="/usb-devices"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-300 hover:text-white hover:bg-[#25262c] transition"
                        :class="{ 'bg-[#25262c] text-white border-l-2 border-[#e57300]': $page.url.startsWith('/usb-devices') }"
                    >
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <span class="font-medium">USB Devices</span>
                    </Link>

                    <!-- Logs -->
                    <Link
                        href="/activity-logs"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-300 hover:text-white hover:bg-[#25262c] transition"
                        :class="{ 'bg-[#25262c] text-white border-l-2 border-[#e57300]': $page.url === '/activity-logs' }"
                    >
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="font-medium">Logs</span>
                    </Link>

                    <!-- Settings -->
                    <Link
                        href="/profile"
                        class="flex items-center space-x-2.5 px-3 py-2 rounded text-gray-300 hover:text-white hover:bg-[#25262c] transition"
                        :class="{ 'bg-[#25262c] text-white border-l-2 border-[#e57300]': $page.url === '/profile' }"
                    >
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span class="font-medium">Settings</span>
                    </Link>
                </nav>

                <!-- Divider -->
                <div class="border-t border-[#2c2d30] my-1 mx-3"></div>

                <!-- Proxmox Resource Tree Title -->
                <div class="px-4 py-2 flex items-center justify-between text-[11px] text-gray-500 font-bold uppercase tracking-wider">
                    <span>Server Resource Tree</span>
                    <button @click="isTreeExpanded = !isTreeExpanded" class="hover:text-white">
                        <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-180': !isTreeExpanded }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>
                </div>

                <!-- Expandable Tree -->
                <div v-show="isTreeExpanded" class="flex-1 overflow-y-auto px-2 space-y-1 font-mono text-xs">
                    <!-- Host Server Node (Expandable) -->
                    <div class="p-1">
                        <div class="flex items-center space-x-2 px-2 py-1 rounded text-white bg-[#25262c]/50">
                            <svg class="w-4 h-4 text-[#e57300]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                            </svg>
                            <span class="font-semibold text-gray-200">mampa-host</span>
                            <span class="text-[9px] text-[#e57300] bg-[#e57300]/10 px-1 rounded border border-[#e57300]/20 font-bold uppercase">node</span>
                        </div>

                        <!-- Virtual Machines list nested under the node -->
                        <div class="ml-4 pl-2 border-l border-gray-800 mt-1 space-y-0.5">
                            <Link
                                v-for="vm in vms"
                                :key="vm.uuid"
                                :href="`/vms/${vm.uuid}`"
                                class="flex items-center justify-between px-2.5 py-1.5 rounded hover:bg-[#25262c] transition group"
                                :class="{ 'bg-[#25262c] text-white border-l-2 border-[#e57300]': $page.url === `/vms/${vm.uuid}` }"
                            >
                                <div class="flex items-center space-x-2 overflow-hidden">
                                    <!-- VM Status Pulsing Indicator -->
                                    <div class="relative flex h-2 w-2">
                                        <span v-if="vm.status === 'running'" class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" :class="getStatusColor(vm.status)"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2" :class="getStatusColor(vm.status)"></span>
                                    </div>
                                    <svg class="w-3.5 h-3.5 text-gray-500 group-hover:text-gray-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                    <span class="truncate text-gray-300 group-hover:text-white">{{ vm.name }}</span>
                                </div>
                                <span class="text-[9px] text-gray-500 font-mono group-hover:text-gray-400">
                                    ({{ vm.vcpus }}vC)
                                </span>
                            </Link>

                            <!-- Placeholder if no VMs exist -->
                            <div v-if="vms.length === 0" class="px-2 py-3 text-[10px] text-gray-500 italic">
                                No virtual machines defined.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer Server Uptime -->
                <div class="p-3 bg-[#16171b] border-t border-[#2c2d30] text-[10px] text-gray-500 font-mono flex items-center justify-between">
                    <span>Uptime: 24d 18h 42m</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500" title="All Services Operational"></span>
                </div>
            </aside>

            <!-- Main Workpanel Area -->
            <main class="flex-1 flex flex-col min-w-0 overflow-y-auto">
                <div class="p-6 max-w-7xl w-full mx-auto space-y-6">
                    <!-- Page Breadcrumbs -->
                    <div class="flex items-center justify-between shrink-0 select-none pb-2 border-b border-[#2c2d30] text-xs">
                        <div class="flex items-center space-x-1.5 text-gray-400">
                            <Link href="/dashboard" class="hover:text-white transition">Datacenter</Link>
                            <span>/</span>
                            <span class="text-gray-300 font-mono">mampa-host</span>
                            <span v-if="$slots.breadcrumb">
                                <span>/</span>
                                <span class="text-white font-semibold font-mono"><slot name="breadcrumb" /></span>
                            </span>
                        </div>
                        <div class="text-xs text-gray-500 font-mono" id="current-time">
                            July 01, 2026 13:14:36
                        </div>
                    </div>

                    <!-- Flash messages banner -->
                    <div v-if="flashSuccess" class="bg-emerald-500/10 border border-emerald-500/25 text-emerald-400 p-3.5 rounded text-xs font-semibold flex items-center justify-between">
                        <span>{{ flashSuccess }}</span>
                        <button @click="flashSuccess = null" class="text-emerald-400 hover:text-emerald-300 font-extrabold text-base">&times;</button>
                    </div>
                    <div v-if="flashError" class="bg-rose-500/10 border border-rose-500/25 text-rose-400 p-3.5 rounded text-xs font-semibold flex items-center justify-between">
                        <span>{{ flashError }}</span>
                        <button @click="flashError = null" class="text-rose-400 hover:text-rose-300 font-extrabold text-base">&times;</button>
                    </div>

                    <!-- Main Dynamic Page Content slot -->
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
