<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    hostStats: Object,
    activePortsCount: Number,
    recentLogs: Array
});

const isActionLoading = ref({});

const getStatusColor = (status) => {
    switch (status) {
        case 'running':
            return 'bg-emerald-500 text-emerald-100 border-emerald-500/20';
        case 'paused':
            return 'bg-amber-500 text-amber-100 border-amber-500/20';
        default:
            return 'bg-rose-500 text-rose-100 border-rose-500/20';
    }
};

const getStatusText = (status) => {
    if (!status) return 'Unknown';
    if (status === 'shutoff') return 'Stopped';
    return status.charAt(0).toUpperCase() + status.slice(1);
};

const activePlan = ref(null);
const isPlanModalOpen = ref(false);

const requestControlPlan = async (uuid, action) => {
    try {
        const response = await fetch(`/vms/${uuid}/execution-plan/${action}`);
        if (response.ok) {
            activePlan.value = await response.json();
            isPlanModalOpen.value = true;
        }
    } catch (e) {
        console.error("Failed to load execution plan", e);
    }
};

const confirmExecution = () => {
    if (!activePlan.value || activePlan.value.mode === 'readonly') return;
    
    const uuid = activePlan.value.vm_uuid;
    const action = activePlan.value.action;
    const key = `${uuid}-${action}`;
    
    isPlanModalOpen.value = false;
    isActionLoading.value[key] = true;
    
    router.post(`/vms/${uuid}/${action}`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isActionLoading.value[key] = false;
            activePlan.value = null;
        }
    });
};

const formatTime = (timeStr) => {
    const date = new Date(timeStr);
    return date.toLocaleString('en-US', {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });
};

const formatLogDetails = (details) => {
    if (!details) return '';
    return typeof details === 'string' ? details : JSON.stringify(details);
};

// --- VM Creation Wizard State & Methods ---
const isWizardOpen = ref(false);
const wizardStep = ref('general');
const availableISOs = ref([]);
const availablePools = ref([]);
const steps = ['general', 'os', 'system', 'cpu', 'memory', 'disk', 'network', 'review'];

const wizardForm = ref({
    name: '',
    vcpus: 2,
    memory_mb: 4096,
    disk_gb: 32,
    boot_type: 'uefi',
    machine_type: 'pc-q35-6.2',
    disk_bus: 'virtio',
    network_bridge: 'virbr0',
    network_model: 'virtio',
    iso_volume: '',
    description: '',
    usb_controller: true,
    usb_controller_model: 'qemu-xhci',
    start_after_created: false,
    mac_address: ''
});

const formErrors = ref({});
const isSubmitting = ref(false);

const openWizardModal = async () => {
    isWizardOpen.value = true;
    wizardStep.value = 'general';
    try {
        const isoRes = await fetch('/api/storage/isos');
        if (isoRes.ok) availableISOs.value = await isoRes.json();
        
        const poolRes = await fetch('/api/storage/pools');
        if (poolRes.ok) availablePools.value = await poolRes.json();
    } catch (e) {
        console.error("Failed to load ISOs or pools", e);
    }
};

const closeWizardModal = () => {
    isWizardOpen.value = false;
    formErrors.value = {};
    wizardForm.value = {
        name: '',
        vcpus: 2,
        memory_mb: 4096,
        disk_gb: 32,
        boot_type: 'uefi',
        machine_type: 'pc-q35-6.2',
        disk_bus: 'virtio',
        network_bridge: 'virbr0',
        network_model: 'virtio',
        iso_volume: '',
        description: '',
        usb_controller: true,
        usb_controller_model: 'qemu-xhci',
        start_after_created: false,
        mac_address: ''
    };
};

const applyPreset = (template) => {
    if (template === 'windows') {
        wizardForm.value.vcpus = 4;
        wizardForm.value.memory_mb = 8192;
        wizardForm.value.disk_gb = 60;
        wizardForm.value.boot_type = 'uefi';
        wizardForm.value.machine_type = 'pc-q35-6.2';
        wizardForm.value.disk_bus = 'sata';
        wizardForm.value.network_model = 'e1000';
        wizardForm.value.description = 'Windows Template virtual machine.';
    } else if (template === 'ubuntu') {
        wizardForm.value.vcpus = 2;
        wizardForm.value.memory_mb = 2048;
        wizardForm.value.disk_gb = 20;
        wizardForm.value.boot_type = 'bios';
        wizardForm.value.machine_type = 'pc-q35-6.2';
        wizardForm.value.disk_bus = 'virtio';
        wizardForm.value.network_model = 'virtio';
        wizardForm.value.description = 'Ubuntu Template Linux machine.';
    }
};

const nextStep = () => {
    if (wizardStep.value === 'general') {
        if (!wizardForm.value.name) {
            formErrors.value.name = 'VM Name is required.';
            return;
        }
        if (!/^[a-zA-Z0-9_-]+$/.test(wizardForm.value.name)) {
            formErrors.value.name = 'VM Name can only contain letters, numbers, dashes, and underscores.';
            return;
        }
        delete formErrors.value.name;
    }
    
    const currentIndex = steps.indexOf(wizardStep.value);
    if (currentIndex < steps.length - 1) {
        wizardStep.value = steps[currentIndex + 1];
    }
};

const prevStep = () => {
    const currentIndex = steps.indexOf(wizardStep.value);
    if (currentIndex > 0) {
        wizardStep.value = steps[currentIndex - 1];
    }
};

const submitWizard = async () => {
    isSubmitting.value = true;
    formErrors.value = {};
    
    router.post('/vms', wizardForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            closeWizardModal();
        },
        onError: (errors) => {
            formErrors.value = errors;
            if (errors.name) wizardStep.value = 'general';
            else if (errors.vcpus) wizardStep.value = 'cpu';
            else if (errors.memory_mb) wizardStep.value = 'memory';
            else if (errors.disk_gb) wizardStep.value = 'disk';
        },
        onFinish: () => {
            isSubmitting.value = false;
        }
    });
};
</script>

<template>
    <Head title="Hypervisor Datacenter Dashboard" />

    <AuthenticatedLayout>
        <template #breadcrumb>Summary</template>

        <!-- Top Widgets Row (Host Stats Overview) -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- Node Status -->
            <div class="bg-[#1c1d22] border border-[#2c2d30] rounded p-4 flex flex-col justify-between h-28">
                <span class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Node Status</span>
                <div class="flex items-center space-x-2.5">
                    <span class="flex h-3.5 w-3.5 relative">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3.5 w-3.5 bg-emerald-500"></span>
                    </span>
                    <span class="text-lg font-bold font-mono text-white">ONLINE</span>
                </div>
                <span class="text-[10px] text-gray-500 font-mono">mampa-host.local</span>
            </div>

            <!-- Virtual Machines Ratio -->
            <div class="bg-[#1c1d22] border border-[#2c2d30] rounded p-4 flex flex-col justify-between h-28">
                <span class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Virtual Machines</span>
                <div class="flex items-baseline space-x-1.5">
                    <span class="text-3xl font-extrabold font-mono text-white">{{ hostStats.running_vms }}</span>
                    <span class="text-gray-500 text-sm">/ {{ hostStats.total_vms }} Running</span>
                </div>
                <span class="text-[10px] text-gray-400 font-mono">
                    {{ hostStats.paused_vms }} paused, {{ hostStats.total_vms - hostStats.running_vms - hostStats.paused_vms }} offline
                </span>
            </div>

            <!-- Resource Allocations -->
            <div class="bg-[#1c1d22] border border-[#2c2d30] rounded p-4 flex flex-col justify-between h-28">
                <span class="text-xs text-gray-500 uppercase tracking-wide font-semibold">CPU Cores Allocated</span>
                <div class="flex items-baseline space-x-1.5">
                    <span class="text-3xl font-extrabold font-mono text-[#e57300]">{{ hostStats.total_cpus_allocated }}</span>
                    <span class="text-gray-500 text-sm">/ {{ hostStats.host_cpu_cores }} Cores</span>
                </div>
                <span class="text-[10px] text-gray-500 font-mono">
                    {{ Math.round((hostStats.total_cpus_allocated / hostStats.host_cpu_cores) * 100) }}% total allocation
                </span>
            </div>

            <!-- Memory Allocation -->
            <div class="bg-[#1c1d22] border border-[#2c2d30] rounded p-4 flex flex-col justify-between h-28">
                <span class="text-xs text-gray-500 uppercase tracking-wide font-semibold">Memory Allocated</span>
                <div class="flex items-baseline space-x-1.5">
                    <span class="text-3xl font-extrabold font-mono text-[#e57300]">{{ hostStats.total_memory_allocated_gb }}</span>
                    <span class="text-gray-500 text-sm">GB / {{ hostStats.host_memory_total_gb }} GB</span>
                </div>
                <span class="text-[10px] text-gray-500 font-mono">
                    {{ Math.round((hostStats.total_memory_allocated_gb / hostStats.host_memory_total_gb) * 100) }}% total allocation
                </span>
            </div>
        </div>

        <!-- Node Hardware Resource Meters -->
        <div class="bg-[#1c1d22] border border-[#2c2d30] rounded">
            <div class="px-4 py-3 border-b border-[#2c2d30] bg-[#16171b] flex items-center justify-between">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-300">Host Server Resource Utilization</h3>
                <span class="text-[10px] text-gray-500 font-mono">Updated: Realtime (1s interval)</span>
            </div>
            <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-6 font-mono text-xs">
                <!-- CPU Load Meter -->
                <div class="space-y-2">
                    <div class="flex justify-between items-center text-gray-400">
                        <span>CPU Usage</span>
                        <span class="text-white font-semibold">14.2%</span>
                    </div>
                    <div class="h-3 bg-gray-800 rounded-sm overflow-hidden border border-gray-700">
                        <div class="bg-gradient-to-r from-[#e57300] to-orange-400 h-full rounded-sm transition-all duration-500" style="width: 14.2%"></div>
                    </div>
                    <div class="text-[10px] text-gray-500 flex justify-between">
                        <span>Load Average: 0.42, 0.58, 0.50</span>
                        <span>12 Cores</span>
                    </div>
                </div>

                <!-- Memory Usage Meter -->
                <div class="space-y-2">
                    <div class="flex justify-between items-center text-gray-400">
                        <span>RAM Memory Usage</span>
                        <span class="text-white font-semibold">4.8 GB / 16.0 GB (30%)</span>
                    </div>
                    <div class="h-3 bg-gray-800 rounded-sm overflow-hidden border border-gray-700">
                        <div class="bg-gradient-to-r from-[#e57300] to-orange-400 h-full rounded-sm transition-all duration-500" style="width: 30%"></div>
                    </div>
                    <div class="text-[10px] text-gray-500 flex justify-between">
                        <span>Buffer/Cache: 2.1 GB</span>
                        <span>Free: 9.1 GB</span>
                    </div>
                </div>

                <!-- Disk Usage Meter -->
                <div class="space-y-2">
                    <div class="flex justify-between items-center text-gray-400">
                        <span>Storage (LVM-thin)</span>
                        <span class="text-white font-semibold">245 GB / 1,000 GB (24.5%)</span>
                    </div>
                    <div class="h-3 bg-gray-800 rounded-sm overflow-hidden border border-gray-700">
                        <div class="bg-gradient-to-r from-[#e57300] to-orange-400 h-full rounded-sm transition-all duration-500" style="width: 24.5%"></div>
                    </div>
                    <div class="text-[10px] text-gray-500 flex justify-between">
                        <span>VM Disks: 220 GB</span>
                        <span>Free Storage: 755 GB</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Virtual Machines List Table -->
        <div class="bg-[#1c1d22] border border-[#2c2d30] rounded">
            <div class="px-4 py-3 border-b border-[#2c2d30] bg-[#16171b] flex items-center justify-between">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-300">Virtual Machine Nodes Summary</h3>
                <div class="flex items-center space-x-3">
                    <button 
                        @click="openWizardModal"
                        class="bg-[#e57300] hover:bg-orange-600 text-black font-bold px-3 py-1 rounded text-[10px] uppercase transition flex items-center space-x-1"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span>Create VM</span>
                    </button>
                    <span class="text-[10px] text-gray-500 font-mono">KVM Driver active</span>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-[#2c2d30] text-gray-400 bg-black/20 select-none">
                            <th class="p-3 font-semibold">VM Name</th>
                            <th class="p-3 font-semibold">Status</th>
                            <th class="p-3 font-semibold">Allocated vCPU</th>
                            <th class="p-3 font-semibold">Memory</th>
                            <th class="p-3 font-semibold">Internal IP</th>
                            <th class="p-3 font-semibold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#2c2d30] font-mono">
                        <tr v-for="vm in $page.props.vms" :key="vm.uuid" class="hover:bg-[#25262c]/30 transition group">
                            <!-- VM Name -->
                            <td class="p-3 font-semibold">
                                <Link :href="`/vms/${vm.uuid}`" class="text-white hover:text-[#e57300] hover:underline flex items-center space-x-2">
                                    <svg class="w-3.5 h-3.5 text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                    </svg>
                                    <span>{{ vm.name }}</span>
                                </Link>
                            </td>

                            <!-- VM Status -->
                            <td class="p-3">
                                <span class="inline-flex items-center space-x-1.5 px-2 py-0.5 rounded text-[10px] uppercase font-bold border" :class="getStatusColor(vm.status)">
                                    <span class="h-1.5 w-1.5 rounded-full bg-current" :class="{ 'animate-pulse': vm.status === 'running' }"></span>
                                    <span>{{ getStatusText(vm.status) }}</span>
                                </span>
                            </td>

                            <!-- vCPUs -->
                            <td class="p-3 text-gray-300">{{ vm.vcpus }} Cores</td>

                            <!-- Memory -->
                            <td class="p-3 text-gray-300">{{ vm.memory_mb }} MB</td>

                            <!-- IP Address -->
                            <td class="p-3 text-gray-400">{{ vm.ip_address || 'Unassigned' }}</td>

                            <!-- Action Buttons -->
                            <td class="p-3 text-right space-x-1.5 whitespace-nowrap">
                                <!-- Start -->
                                <button
                                    v-if="vm.status === 'shutoff'"
                                    @click="requestControlPlan(vm.uuid, 'start')"
                                    :disabled="isActionLoading[`${vm.uuid}-start`]"
                                    class="bg-emerald-600 hover:bg-emerald-500 text-white font-sans text-[10px] px-2 py-1 rounded transition disabled:opacity-50"
                                >
                                    {{ isActionLoading[`${vm.uuid}-start`] ? 'Starting...' : 'Start' }}
                                </button>

                                <!-- Suspend (when running) -->
                                <button
                                    v-if="vm.status === 'running'"
                                    @click="requestControlPlan(vm.uuid, 'suspend')"
                                    :disabled="isActionLoading[`${vm.uuid}-suspend`]"
                                    class="bg-amber-600 hover:bg-amber-500 text-white font-sans text-[10px] px-2 py-1 rounded transition disabled:opacity-50"
                                >
                                    Pause
                                </button>

                                <!-- Resume (when paused) -->
                                <button
                                    v-if="vm.status === 'paused'"
                                    @click="requestControlPlan(vm.uuid, 'resume')"
                                    :disabled="isActionLoading[`${vm.uuid}-resume`]"
                                    class="bg-emerald-600 hover:bg-emerald-500 text-white font-sans text-[10px] px-2 py-1 rounded transition disabled:opacity-50"
                                >
                                    Resume
                                </button>

                                <!-- Shutdown (graceful, when running) -->
                                <button
                                    v-if="vm.status === 'running'"
                                    @click="requestControlPlan(vm.uuid, 'stop')"
                                    :disabled="isActionLoading[`${vm.uuid}-stop`]"
                                    class="bg-gray-700 hover:bg-gray-600 text-white font-sans text-[10px] px-2 py-1 rounded transition disabled:opacity-50"
                                >
                                    Stop
                                </button>

                                <!-- Force Stop (always when not stopped) -->
                                <button
                                    v-if="vm.status !== 'shutoff'"
                                    @click="requestControlPlan(vm.uuid, 'force-stop')"
                                    :disabled="isActionLoading[`${vm.uuid}-force-stop`]"
                                    class="bg-rose-600 hover:bg-rose-500 text-white font-sans text-[10px] px-2 py-1 rounded transition disabled:opacity-50"
                                >
                                    Kill
                                </button>

                                <!-- Reboot -->
                                <button
                                    v-if="vm.status === 'running'"
                                    @click="requestControlPlan(vm.uuid, 'reboot')"
                                    :disabled="isActionLoading[`${vm.uuid}-reboot`]"
                                    class="border border-gray-600 hover:border-white text-gray-300 hover:text-white font-sans text-[10px] px-2 py-0.5 rounded transition disabled:opacity-50"
                                >
                                    Reboot
                                </button>
                            </td>
                        </tr>
                        <tr v-if="$page.props.vms.length === 0">
                            <td colspan="6" class="p-4 text-center text-gray-500 italic">No virtual machines available on this host.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Lower Grid: Activity Logs -->
        <div class="bg-[#1c1d22] border border-[#2c2d30] rounded">
            <div class="px-4 py-3 border-b border-[#2c2d30] bg-[#16171b] flex items-center justify-between">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-300">Recent Tasks & Audit Log</h3>
                <Link href="/activity-logs" class="text-[#e57300] hover:text-orange-400 text-xs font-sans">View Full Logs →</Link>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[11px] font-mono">
                    <thead>
                        <tr class="border-b border-[#2c2d30] text-gray-400 bg-black/20 select-none">
                            <th class="p-2.5">Timestamp</th>
                            <th class="p-2.5">User</th>
                            <th class="p-2.5">Action</th>
                            <th class="p-2.5">Parameters</th>
                            <th class="p-2.5">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#2c2d30]">
                        <tr v-for="log in recentLogs" :key="log.id" class="hover:bg-[#25262c]/20 transition">
                            <td class="p-2.5 text-gray-400">{{ formatTime(log.created_at) }}</td>
                            <td class="p-2.5 text-gray-300 font-semibold">{{ log.user ? log.user.name : 'System' }}</td>
                            <td class="p-2.5">
                                <span class="text-[#e57300] bg-[#e57300]/10 px-1 py-0.5 rounded border border-[#e57300]/25 font-semibold text-[10px]">
                                    {{ log.action }}
                                </span>
                            </td>
                            <td class="p-2.5 text-gray-400 max-w-sm truncate" :title="formatLogDetails(log.details)">
                                {{ formatLogDetails(log.details) }}
                            </td>
                            <td class="p-2.5 text-gray-500">{{ log.ip_address || '-' }}</td>
                        </tr>
                        <tr v-if="recentLogs.length === 0">
                            <td colspan="5" class="p-4 text-center text-gray-500 italic">No activity logs recorded.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Command Audit Preview / Execution Plan Modal -->
        <div v-if="isPlanModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 backdrop-blur-sm p-4 font-sans text-xs">
            <div class="bg-[#1c1d22] border border-[#2c2d30] shadow-2xl rounded max-w-lg w-full overflow-hidden">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-[#2c2d30] bg-[#16171b] flex justify-between items-center select-none">
                    <h3 class="font-bold text-gray-200 uppercase tracking-wider">Command Execution Plan Preview</h3>
                    <button @click="isPlanModalOpen = false" class="text-gray-400 hover:text-white font-bold text-base">&times;</button>
                </div>
                
                <!-- Body -->
                <div class="p-5 space-y-4 font-mono">
                    <div class="grid grid-cols-3 gap-2 border-b border-[#2c2d30]/60 pb-3">
                        <span class="text-gray-500 font-sans">VM Target:</span>
                        <span class="col-span-2 text-white font-semibold">{{ activePlan.vm_name }}</span>
                        
                        <span class="text-gray-500 font-sans">VM UUID:</span>
                        <span class="col-span-2 text-gray-400 text-[10px]">{{ activePlan.vm_uuid }}</span>

                        <span class="text-gray-500 font-sans">Safety Mode:</span>
                        <span class="col-span-2">
                            <span v-if="activePlan.mode === 'readonly'" class="bg-rose-500/10 text-rose-400 border border-rose-500/25 px-1.5 py-0.5 rounded text-[10px] uppercase font-sans font-bold">
                                Read-Only (Blocked)
                            </span>
                            <span v-else class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/25 px-1.5 py-0.5 rounded text-[10px] uppercase font-sans font-bold">
                                Active Mode
                            </span>
                        </span>
                    </div>

                    <!-- Command preview -->
                    <div class="space-y-1">
                        <span class="text-gray-500 font-sans block">Raw Command to Execute:</span>
                        <div class="bg-black/50 border border-[#2c2d30] p-2.5 rounded text-[#e57300] font-semibold break-all text-[11px]">
                            {{ activePlan.command }}
                        </div>
                    </div>

                    <!-- Risk, Expected and Rollback info -->
                    <div class="grid grid-cols-3 gap-y-3 gap-x-2 pt-2 border-t border-[#2c2d30]/60 text-[11px]">
                        <span class="text-gray-500 font-sans">Risk Level:</span>
                        <span class="col-span-2 font-sans font-semibold">
                            <span v-if="activePlan.risk_level === 'HIGH'" class="text-rose-400">● HIGH RISK (Data loss warning)</span>
                            <span v-else-if="activePlan.risk_level === 'MEDIUM'" class="text-amber-400">● MEDIUM RISK</span>
                            <span v-else class="text-emerald-400">● LOW RISK</span>
                        </span>

                        <span class="text-gray-500 font-sans">Expected Result:</span>
                        <span class="col-span-2 text-gray-300 font-sans">{{ activePlan.expected_result }}</span>

                        <span class="text-gray-500 font-sans">Rollback Option:</span>
                        <span class="col-span-2 text-gray-400 font-sans">{{ activePlan.rollback_option }}</span>
                    </div>

                    <!-- Warning if readonly -->
                    <div v-if="activePlan.mode === 'readonly'" class="bg-rose-500/10 border border-rose-500/25 p-3 rounded text-rose-300 font-sans leading-relaxed text-[11px]">
                        <strong>Block Alert:</strong> This command cannot be executed because the hypervisor is operating under a global read-only policy. Set <code class="bg-black/40 px-1 rounded text-orange-400">HYPERVISOR_MODE=active</code> in `.env` to execute lifecycle actions.
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-5 py-3 border-t border-[#2c2d30] bg-[#16171b] flex justify-end space-x-2 font-sans">
                    <button
                        @click="isPlanModalOpen = false"
                        class="px-3.5 py-1.5 rounded border border-[#2c2d30] text-gray-400 hover:text-white transition text-xs"
                    >
                        Cancel
                    </button>
                    <button
                        v-if="activePlan.mode !== 'readonly'"
                        @click="confirmExecution"
                        class="bg-[#e57300] hover:bg-orange-600 text-black font-bold px-4 py-1.5 rounded transition text-xs"
                    >
                        Confirm Execution
                    </button>
                    <button
                        v-else
                        disabled
                        class="bg-gray-800 text-gray-500 border border-gray-700/60 font-semibold px-4 py-1.5 rounded cursor-not-allowed text-xs"
                    >
                        Execution Blocked
                    </button>
                </div>
            </div>
        </div>

        <!-- Create VM Wizard Modal (Proxmox-style) -->
        <div v-if="isWizardOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 backdrop-blur-sm p-4 font-sans text-xs">
            <div class="bg-[#1c1d22] border border-[#2c2d30] shadow-2xl rounded max-w-2xl w-full overflow-hidden flex flex-col h-[460px]">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-[#2c2d30] bg-[#16171b] flex justify-between items-center select-none text-left">
                    <h3 class="font-bold text-gray-200 uppercase tracking-wider">Create: Virtual Machine</h3>
                    <button @click="closeWizardModal" class="text-gray-400 hover:text-white font-bold text-base">&times;</button>
                </div>

                <!-- Tabs (Proxmox style) -->
                <div class="flex border-b border-[#2c2d30] bg-[#111214] overflow-x-auto text-[10px] select-none uppercase font-bold text-center shrink-0">
                    <button 
                        v-for="step in steps" 
                        :key="step"
                        @click="steps.indexOf(step) <= steps.indexOf(wizardStep) ? wizardStep = step : null"
                        class="px-4 py-3 flex-1 border-r border-[#2c2d30] transition min-w-[70px]"
                        :class="wizardStep === step 
                            ? 'bg-[#e57300] text-black' 
                            : (steps.indexOf(step) < steps.indexOf(wizardStep) ? 'text-gray-300 hover:bg-[#1c1d22]/50' : 'text-gray-600 cursor-not-allowed')"
                    >
                        {{ step }}
                    </button>
                </div>

                <!-- Wizard Step Content -->
                <div class="p-6 flex-1 overflow-y-auto text-left space-y-4">
                    
                    <!-- STEP 1: General -->
                    <div v-if="wizardStep === 'general'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">1</span>
                            <span class="text-gray-300 font-bold uppercase">General VM Settings</span>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-gray-400 font-semibold">Node</label>
                                <input type="text" value="mampa-host" class="w-full bg-[#111214]/50 border border-[#2c2d30] rounded p-2 text-gray-500 font-mono focus:outline-none" disabled />
                            </div>
                            <div class="space-y-1">
                                <label class="block text-gray-400 font-semibold">VM ID</label>
                                <input type="number" value="100" class="w-full bg-[#111214]/50 border border-[#2c2d30] rounded p-2 text-gray-500 font-mono focus:outline-none" disabled />
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-gray-300 font-semibold">VM Name</label>
                            <input 
                                v-model="wizardForm.name" 
                                type="text" 
                                placeholder="e.g. windows10"
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] focus:ring-1 focus:ring-[#e57300]"
                                required
                            />
                            <span v-if="formErrors.name" class="text-rose-500 text-[10px] block mt-0.5">{{ formErrors.name }}</span>
                        </div>

                        <div class="bg-black/20 p-3 rounded border border-[#2c2d30]/65 space-y-2 select-none">
                            <span class="text-gray-400 font-semibold block text-[10px] uppercase">Template Presets</span>
                            <div class="flex space-x-2">
                                <button type="button" @click="applyPreset('windows')" class="bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700 px-3 py-1.5 rounded transition">
                                    Windows Template
                                </button>
                                <button type="button" @click="applyPreset('ubuntu')" class="bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700 px-3 py-1.5 rounded transition">
                                    Ubuntu Template
                                </button>
                            </div>
                            <span class="text-[10px] text-gray-500 block">Pre-fill configuration for optimized Guest hardware parameters.</span>
                        </div>
                    </div>

                    <!-- STEP 2: OS -->
                    <div v-if="wizardStep === 'os'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">2</span>
                            <span class="text-gray-300 font-bold uppercase">Operating System</span>
                        </div>

                        <div class="space-y-3">
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">ISO Image (CD/DVD Volume)</label>
                                <select 
                                    v-model="wizardForm.iso_volume"
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] focus:ring-1 focus:ring-[#e57300]"
                                >
                                    <option value="">No media (Boot from blank disk)</option>
                                    <option v-for="iso in availableISOs" :key="iso" :value="iso">{{ iso }}</option>
                                </select>
                            </div>

                            <div class="space-y-1">
                                <label class="block text-gray-400 font-semibold">Guest OS Type hints</label>
                                <select class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none" disabled>
                                    <option>Linux 6.x / 5.x / 4.x Kernel</option>
                                    <option>Microsoft Windows 11/10/Server</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 3: System -->
                    <div v-if="wizardStep === 'system'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">3</span>
                            <span class="text-gray-300 font-bold uppercase">System BIOS & Motherboard</span>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">BIOS / firmware</label>
                                <select 
                                    v-model="wizardForm.boot_type"
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300]"
                                >
                                    <option value="bios">Default BIOS (SeaBIOS)</option>
                                    <option value="uefi">UEFI (OVMF firmware)</option>
                                </select>
                            </div>
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">Machine Model</label>
                                <select 
                                    v-model="wizardForm.machine_type"
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300]"
                                >
                                    <option value="pc-q35-6.2">Q35 standard (pc-q35-6.2)</option>
                                    <option value="i440fx">i440fx compatibility</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col bg-black/10 p-2.5 rounded border border-[#2c2d30] space-y-2">
                                <div class="flex items-center space-x-2.5">
                                    <input v-model="wizardForm.usb_controller" type="checkbox" id="usb_controller" class="rounded text-[#e57300] focus:ring-[#e57300] bg-black border-gray-700" />
                                    <label for="usb_controller" class="text-gray-300 cursor-pointer">Add USB Tablet / Keyboard</label>
                                </div>
                                <div v-if="wizardForm.usb_controller" class="space-y-1">
                                    <label class="block text-[10px] text-gray-400 font-semibold uppercase">USB Controller Model</label>
                                    <select 
                                        v-model="wizardForm.usb_controller_model"
                                        class="w-full bg-[#111214] border border-[#2c2d30] rounded p-1.5 text-white focus:outline-none focus:border-[#e57300] text-xs"
                                    >
                                        <option value="qemu-xhci">USB 3.0 (qemu-xhci - Win 10/11, Linux)</option>
                                        <option value="ich9-ehci1">USB 2.0 (ich9-ehci1 - Win 7/XP)</option>
                                        <option value="piix3-uhci">USB 1.1 (piix3-uhci - Legacy)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2.5 bg-black/10 p-2.5 rounded border border-[#2c2d30] opacity-50 cursor-not-allowed">
                                <input type="checkbox" id="tpm_controller" class="rounded bg-black border-gray-700" disabled />
                                <label for="tpm_controller" class="text-gray-500 cursor-not-allowed">Add TPM 2.0 State (Future)</label>
                            </div>
                        </div>
                    </div>

                    <!-- STEP 4: CPU -->
                    <div v-if="wizardStep === 'cpu'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">4</span>
                            <span class="text-gray-300 font-bold uppercase">CPU Core Allocation</span>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">Sockets</label>
                                <input type="number" value="1" class="w-full bg-[#111214]/50 border border-[#2c2d30] rounded p-2 text-gray-500 focus:outline-none" disabled />
                            </div>
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">Processor Cores</label>
                                <input 
                                    v-model="wizardForm.vcpus"
                                    type="number" 
                                    min="1" 
                                    max="32"
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300]"
                                    required
                                />
                                <span v-if="formErrors.vcpus" class="text-rose-500 text-[10px] block mt-0.5">{{ formErrors.vcpus }}</span>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Emulated CPU Model Type</label>
                            <input type="text" value="host-passthrough (native speed)" class="w-full bg-[#111214]/50 border border-[#2c2d30] rounded p-2 text-gray-500 focus:outline-none" disabled />
                        </div>
                    </div>

                    <!-- STEP 5: Memory -->
                    <div v-if="wizardStep === 'memory'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">5</span>
                            <span class="text-gray-300 font-bold uppercase">RAM Memory Allocation</span>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-gray-300 font-semibold">Memory Size (MB)</label>
                            <input 
                                v-model="wizardForm.memory_mb"
                                type="number" 
                                step="512"
                                min="512" 
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300]"
                                required
                            />
                            <span v-if="formErrors.memory_mb" class="text-rose-500 text-[10px] block mt-0.5">{{ formErrors.memory_mb }}</span>
                            <span class="text-[10px] text-gray-500 font-mono block mt-1">Allocation: {{ (wizardForm.memory_mb / 1024).toFixed(1) }} GB RAM</span>
                        </div>
                    </div>

                    <!-- STEP 6: Disk -->
                    <div v-if="wizardStep === 'disk'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">6</span>
                            <span class="text-gray-300 font-bold uppercase">Hard Disk</span>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">Bus / Device interface</label>
                                <select 
                                    v-model="wizardForm.disk_bus"
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300]"
                                >
                                    <option value="virtio">VirtIO Block (Standard Linux)</option>
                                    <option value="sata">SATA (Standard Windows)</option>
                                    <option value="scsi">SCSI (Enterprise LVM)</option>
                                    <option value="ide">IDE Legacy</option>
                                </select>
                            </div>
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">Storage Pool Destination</label>
                                <select 
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none"
                                >
                                    <option v-for="pool in availablePools" :key="pool.name" :value="pool.name">
                                        {{ pool.name }} ({{ pool.path }})
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-gray-300 font-semibold">Disk Size (GB)</label>
                            <input 
                                v-model="wizardForm.disk_gb"
                                type="number" 
                                min="5" 
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300]"
                                required
                            />
                            <span v-if="formErrors.disk_gb" class="text-rose-500 text-[10px] block mt-0.5">{{ formErrors.disk_gb }}</span>
                        </div>
                    </div>

                    <!-- STEP 7: Network -->
                    <div v-if="wizardStep === 'network'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">7</span>
                            <span class="text-gray-300 font-bold uppercase">Host Network Configuration</span>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">Host Interface Bridge</label>
                                <input 
                                    v-model="wizardForm.network_bridge"
                                    type="text"
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300]"
                                    required
                                />
                            </div>
                            <div class="space-y-1">
                                <label class="block text-gray-300 font-semibold">Device Model</label>
                                <select 
                                    v-model="wizardForm.network_model"
                                    class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300]"
                                >
                                    <option value="virtio">VirtIO (Paravirtualized)</option>
                                    <option value="e1000">Intel e1000 (Gigabit)</option>
                                    <option value="rtl8139">Realtek rtl8139 (Legacy)</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-1">
                            <label class="block text-gray-300 font-semibold">MAC Address (leave blank for auto-generate)</label>
                            <input 
                                v-model="wizardForm.mac_address"
                                type="text"
                                placeholder="52:54:00:xx:xx:xx"
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300]"
                            />
                        </div>
                    </div>

                    <!-- STEP 8: Review -->
                    <div v-if="wizardStep === 'review'" class="space-y-4">
                        <div class="flex items-center space-x-2 pb-2 border-b border-[#2c2d30]">
                            <span class="bg-[#e57300] text-black font-extrabold px-1.5 py-0.5 rounded text-[10px]">8</span>
                            <span class="text-gray-300 font-bold uppercase">Confirm Specifications</span>
                        </div>

                        <div class="border border-[#2c2d30] rounded overflow-hidden select-none bg-black/10 text-[10px]">
                            <table class="w-full text-left font-mono">
                                <thead>
                                    <tr class="bg-black/45 border-b border-[#2c2d30] text-gray-400">
                                        <th class="p-2">Parameter</th>
                                        <th class="p-2">Value</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-[#2c2d30] text-gray-300">
                                    <tr><td class="p-2 font-sans font-semibold">VM Name:</td><td class="p-2 text-white">{{ wizardForm.name }}</td></tr>
                                    <tr><td class="p-2 font-sans font-semibold">ISO Volume:</td><td class="p-2">{{ wizardForm.iso_volume || 'None (CDROM blank)' }}</td></tr>
                                    <tr><td class="p-2 font-sans font-semibold">Firmware BIOS:</td><td class="p-2 uppercase">{{ wizardForm.boot_type }}</td></tr>
                                    <tr><td class="p-2 font-sans font-semibold">Machine Chipset:</td><td class="p-2">{{ wizardForm.machine_type }}</td></tr>
                                    <tr><td class="p-2 font-sans font-semibold">vCPU Allocation:</td><td class="p-2 text-white font-bold">{{ wizardForm.vcpus }} Cores</td></tr>
                                    <tr><td class="p-2 font-sans font-semibold">RAM Allocation:</td><td class="p-2 text-white font-bold">{{ wizardForm.memory_mb }} MB</td></tr>
                                    <tr><td class="p-2 font-sans font-semibold">Virtual Disk:</td><td class="p-2 text-white">{{ wizardForm.disk_gb }} GB (Bus: {{ wizardForm.disk_bus }})</td></tr>
                                    <tr><td class="p-2 font-sans font-semibold">NIC Model:</td><td class="p-2">{{ wizardForm.network_model }} (Bridge: {{ wizardForm.network_bridge }})</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center space-x-2 bg-black/20 p-2.5 rounded border border-[#2c2d30]/65 select-none">
                            <input v-model="wizardForm.start_after_created" type="checkbox" id="start_after_created" class="rounded text-[#e57300] focus:ring-[#e57300] bg-black border-gray-700" />
                            <label for="start_after_created" class="text-gray-300 font-semibold cursor-pointer">Start after virtual machine configuration defined</label>
                        </div>
                    </div>
                </div>

                <!-- Footer (Back / Next / Create buttons) -->
                <div class="px-5 py-4 border-t border-[#2c2d30] bg-[#16171b] flex justify-between items-center font-sans shrink-0">
                    <button
                        @click="closeWizardModal"
                        class="px-3.5 py-1.5 rounded border border-[#2c2d30] text-gray-400 hover:text-white transition text-xs"
                    >
                        Cancel
                    </button>
                    
                    <div class="flex space-x-2">
                        <button
                            v-if="wizardStep !== 'general'"
                            @click="prevStep"
                            class="px-3.5 py-1.5 rounded border border-[#2c2d30] text-gray-300 hover:text-white transition text-xs"
                        >
                            Back
                        </button>
                        <button
                            v-if="wizardStep !== 'review'"
                            @click="nextStep"
                            class="bg-[#e57300] hover:bg-orange-600 text-black font-bold px-4 py-1.5 rounded transition text-xs"
                        >
                            Next
                        </button>
                        <button
                            v-else
                            @click="submitWizard"
                            :disabled="isSubmitting"
                            class="bg-emerald-600 hover:bg-emerald-500 text-white font-bold px-4 py-1.5 rounded transition text-xs disabled:opacity-50"
                        >
                            {{ isSubmitting ? 'Creating VM...' : 'Finish & Create' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
