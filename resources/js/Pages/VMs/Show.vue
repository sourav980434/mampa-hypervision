<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, computed } from 'vue';

const props = defineProps({
    vm: Object,
    rdpMappings: Array,
    publishedApps: Array
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const activeTab = ref('summary');

// Realtime Stats Tracking
const cpuHistory = ref(Array(20).fill(0));
const memHistory = ref(Array(20).fill(0));
const diskReadHistory = ref(Array(20).fill(0));
const diskWriteHistory = ref(Array(20).fill(0));
const netRxHistory = ref(Array(20).fill(0));
const netTxHistory = ref(Array(20).fill(0));

const currentStats = ref({
    cpu_usage_pct: 0,
    memory_usage_mb: 0,
    memory_usage_pct: 0,
    disk_read_kbps: 0,
    disk_write_kbps: 0,
    net_rx_kbps: 0,
    net_tx_kbps: 0
});

let statsInterval = null;

const fetchStats = async () => {
    if (props.vm.status !== 'running') return;
    try {
        const response = await fetch(`/vms/${props.vm.uuid}/stats`);
        if (response.ok) {
            const data = await response.json();
            currentStats.value = data;

            // Push stats to history arrays
            cpuHistory.value.push(data.cpu_usage_pct);
            cpuHistory.value.shift();

            memHistory.value.push(data.memory_usage_pct);
            memHistory.value.shift();

            diskReadHistory.value.push(data.disk_read_kbps);
            diskReadHistory.value.shift();
            diskWriteHistory.value.push(data.disk_write_kbps);
            diskWriteHistory.value.shift();

            netRxHistory.value.push(data.net_rx_kbps);
            netRxHistory.value.shift();
            netTxHistory.value.push(data.net_tx_kbps);
            netTxHistory.value.shift();
        }
    } catch (e) {
        console.error("Failed to fetch VM stats", e);
    }
};

const availableISOs = ref([]);

const fetchISOs = async () => {
    try {
        const response = await fetch('/api/storage/isos');
        if (response.ok) {
            availableISOs.value = await response.json();
        }
    } catch (e) {
        console.error("Failed to load ISOs", e);
    }
};

onMounted(() => {
    fetchStats();
    statsInterval = setInterval(fetchStats, 2000);
    fetchISOs();
});

onUnmounted(() => {
    if (statsInterval) clearInterval(statsInterval);
});

// Metadata Form
const metadataForm = useForm({
    tags: props.vm.tags || [],
    notes: props.vm.notes || '',
    newTag: ''
});

const addTag = () => {
    const tag = metadataForm.newTag.trim();
    if (tag && !metadataForm.tags.includes(tag)) {
        metadataForm.tags.push(tag);
        metadataForm.newTag = '';
    }
};

const removeTag = (index) => {
    metadataForm.tags.splice(index, 1);
};

const submitMetadata = () => {
    metadataForm.post(`/vms/${props.vm.uuid}/metadata`, {
        preserveScroll: true
    });
};

// Controls Loading states
const isActionLoading = ref(false);

// Delete VM Action
const handleDelete = () => {
    if (user.value.role !== 'admin') {
        alert("Unauthorized. Only administrators can delete virtual machines.");
        return;
    }

    if (props.vm.status === 'running' || props.vm.status === 'paused') {
        alert(`Cannot delete: VM '${props.vm.name}' is currently ${props.vm.status}. Please stop/kill the VM before deleting.`);
        return;
    }

    if (confirm(`⚠️ DANGER: Are you sure you want to permanently delete VM '${props.vm.name}'?\nThis will undefine the VM and remove all associated configurations and database settings.`)) {
        isActionLoading.value = true;
        router.delete(`/vms/${props.vm.uuid}`, {
            onFinish: () => {
                isActionLoading.value = false;
            }
        });
    }
};

// Edit VM Configuration Action
const isEditModalOpen = ref(false);
const editForm = useForm({
    vcpus: props.vm.vcpus || 2,
    memory_mb: props.vm.memory_mb || 4096,
    disk_gb: props.vm.disk_gb || 32,
    boot_type: props.vm.boot_type || 'bios',
    machine_type: props.vm.machine_type || 'pc-q35-6.2',
    disk_bus: props.vm.disk_bus || 'virtio',
    network_bridge: props.vm.network_bridge || 'virbr0',
    network_model: props.vm.network_model || 'virtio',
    description: props.vm.description || '',
    usb_controller: props.vm.usb_controller !== false,
    usb_controller_model: props.vm.usb_controller_model || 'qemu-xhci',
    iso_volume: props.vm.iso_volume || '',
});

const openEditModal = () => {
    if (user.value.role !== 'admin') {
        alert("Unauthorized. Only administrators can edit virtual machines.");
        return;
    }

    if (props.vm.status === 'running' || props.vm.status === 'paused') {
        alert(`Cannot edit: VM '${props.vm.name}' is currently ${props.vm.status}. Please stop the VM before editing hardware configuration.`);
        return;
    }

    isEditModalOpen.value = true;
};

const submitEditForm = () => {
    editForm.post(`/vms/${props.vm.uuid}/update`, {
        preserveScroll: true,
        onSuccess: () => {
            isEditModalOpen.value = false;
        }
    });
};

const activePlan = ref(null);
const isPlanModalOpen = ref(false);

const requestControlPlan = async (action) => {
    try {
        const response = await fetch(`/vms/${props.vm.uuid}/execution-plan/${action}`);
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
    
    const action = activePlan.value.action;
    isPlanModalOpen.value = false;
    isActionLoading.value = true;
    
    router.post(`/vms/${props.vm.uuid}/${action}`, {}, {
        preserveScroll: true,
        onFinish: () => {
            isActionLoading.value = false;
            activePlan.value = null;
        }
    });
};

// SVG Path Generator for Charts
const getSvgPath = (data, maxVal = 100) => {
    const width = 400;
    const height = 80;
    const padding = 5;
    
    const step = width / (data.length - 1);
    
    // Scale value based on maxVal
    const points = data.map((val, i) => {
        const x = i * step;
        const clampedVal = Math.min(val, maxVal);
        const y = height - padding - ((clampedVal / maxVal) * (height - padding * 2));
        return `${x},${y}`;
    });

    return `M ${points.join(' L ')}`;
};

const getSvgAreaPath = (data, maxVal = 100) => {
    const width = 400;
    const height = 80;
    const path = getSvgPath(data, maxVal);
    if (!path || path === 'M ') return '';
    return `${path} L ${width},${height} L 0,${height} Z`;
};

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
</script>

<template>
    <Head :title="`VM: ${vm.name}`" />

    <AuthenticatedLayout>
        <template #breadcrumb>{{ vm.name }}</template>

        <!-- VM Controls & Overview -->
        <div class="bg-[#1c1d22] border border-[#2c2d30] rounded p-4 flex flex-col md:flex-row md:items-center justify-between gap-4 select-none">
            <!-- Details info -->
            <div class="flex items-center space-x-4">
                <div class="relative flex h-3 w-3">
                    <span v-if="vm.status === 'running'" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3" :class="getStatusColor(vm.status).split(' ')[0]"></span>
                </div>
                <div>
                    <h2 class="text-base font-bold text-white font-mono flex items-center space-x-2">
                        <span>{{ vm.name }}</span>
                        <span class="text-xs text-gray-500 font-normal">({{ vm.uuid.substring(0, 8) }}...)</span>
                    </h2>
                    <div class="flex flex-wrap gap-1 mt-1">
                        <span v-for="tag in vm.tags" :key="tag" class="bg-gray-800 text-gray-400 px-1.5 py-0.5 rounded text-[9px] font-mono border border-gray-700">
                            {{ tag }}
                        </span>
                        <span v-if="vm.tags.length === 0" class="text-[10px] text-gray-500 italic">No tags</span>
                    </div>
                </div>
            </div>

            <!-- Controls panel -->
            <div class="flex items-center space-x-2 shrink-0">
                <!-- Start -->
                <button
                    v-if="vm.status === 'shutoff'"
                    @click="requestControlPlan('start')"
                    :disabled="isActionLoading"
                    class="bg-emerald-600 hover:bg-emerald-500 text-white font-sans text-xs px-3 py-1.5 rounded transition disabled:opacity-50"
                >
                    Start VM
                </button>

                <!-- Pause/Suspend -->
                <button
                    v-if="vm.status === 'running'"
                    @click="requestControlPlan('suspend')"
                    :disabled="isActionLoading"
                    class="bg-amber-600 hover:bg-amber-500 text-white font-sans text-xs px-3 py-1.5 rounded transition disabled:opacity-50"
                >
                    Pause VM
                </button>

                <!-- Resume -->
                <button
                    v-if="vm.status === 'paused'"
                    @click="requestControlPlan('resume')"
                    :disabled="isActionLoading"
                    class="bg-emerald-600 hover:bg-emerald-500 text-white font-sans text-xs px-3 py-1.5 rounded transition disabled:opacity-50"
                >
                    Resume VM
                </button>

                <!-- Shutdown (Graceful) -->
                <button
                    v-if="vm.status === 'running'"
                    @click="requestControlPlan('stop')"
                    :disabled="isActionLoading"
                    class="bg-gray-700 hover:bg-gray-600 text-white font-sans text-xs px-3 py-1.5 rounded transition disabled:opacity-50"
                >
                    Stop (Shutdown)
                </button>

                <!-- Force Stop -->
                <button
                    v-if="vm.status !== 'shutoff'"
                    @click="requestControlPlan('force-stop')"
                    :disabled="isActionLoading"
                    class="bg-rose-600 hover:bg-rose-500 text-white font-sans text-xs px-3 py-1.5 rounded transition disabled:opacity-50"
                >
                    Kill (Poweroff)
                </button>

                <!-- Reboot -->
                <button
                    v-if="vm.status === 'running'"
                    @click="requestControlPlan('reboot')"
                    :disabled="isActionLoading"
                    class="border border-gray-600 hover:border-white text-gray-300 hover:text-white font-sans text-xs px-3 py-1 rounded transition disabled:opacity-50"
                >
                    Reboot
                </button>

                <!-- Edit Hardware -->
                <button
                    v-if="user.role === 'admin'"
                    @click="openEditModal"
                    :disabled="isActionLoading"
                    class="border border-amber-600 hover:border-amber-400 text-amber-300 hover:text-amber-100 font-sans text-xs px-3 py-1.5 rounded transition disabled:opacity-50 flex items-center space-x-1"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    <span>Edit Hardware</span>
                </button>

                <!-- Delete VM -->
                <button
                    v-if="user.role === 'admin'"
                    @click="handleDelete"
                    :disabled="isActionLoading"
                    class="bg-rose-950 hover:bg-rose-900 border border-rose-800 text-rose-200 hover:text-white font-sans text-xs px-3 py-1.5 rounded transition disabled:opacity-50 flex items-center space-x-1"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                    <span>Delete VM</span>
                </button>
            </div>
        </div>

        <!-- Proxmox-style Details Tab Header -->
        <div class="flex border-b border-[#2c2d30] text-xs font-semibold uppercase tracking-wider select-none bg-[#1c1d22]/50 rounded-t">
            <button
                @click="activeTab = 'summary'"
                class="px-4 py-2.5 transition border-b-2"
                :class="activeTab === 'summary' ? 'border-[#e57300] text-white bg-[#1c1d22]' : 'border-transparent text-gray-400 hover:text-white'"
            >
                Summary
            </button>
            <button
                @click="activeTab = 'hardware'"
                class="px-4 py-2.5 transition border-b-2"
                :class="activeTab === 'hardware' ? 'border-[#e57300] text-white bg-[#1c1d22]' : 'border-transparent text-gray-400 hover:text-white'"
            >
                Hardware Configuration
            </button>
            <button
                @click="activeTab = 'console'"
                class="px-4 py-2.5 transition border-b-2"
                :class="activeTab === 'console' ? 'border-[#e57300] text-white bg-[#1c1d22]' : 'border-transparent text-gray-400 hover:text-white'"
            >
                Console (VNC)
            </button>
            <button
                @click="activeTab = 'metadata'"
                class="px-4 py-2.5 transition border-b-2"
                :class="activeTab === 'metadata' ? 'border-[#e57300] text-white bg-[#1c1d22]' : 'border-transparent text-gray-400 hover:text-white'"
            >
                Notes & Tags
            </button>
        </div>

        <!-- Tab Body Cards -->
        <div class="bg-[#1c1d22] border border-[#2c2d30] border-t-0 rounded-b p-6 min-h-[350px]">
            
            <!-- Summary Tab -->
            <div v-show="activeTab === 'summary'" class="space-y-6">
                <!-- Status metrics cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 font-mono text-xs">
                    <div class="bg-black/20 p-3.5 rounded border border-[#2c2d30]">
                        <span class="text-gray-500 uppercase tracking-wide text-[10px] font-bold block mb-1">State</span>
                        <span class="text-white text-sm font-semibold uppercase">{{ vm.status }}</span>
                    </div>
                    <div class="bg-black/20 p-3.5 rounded border border-[#2c2d30]">
                        <span class="text-gray-500 uppercase tracking-wide text-[10px] font-bold block mb-1">vCPU Cores</span>
                        <span class="text-white text-sm font-semibold">{{ vm.vcpus }} Cores</span>
                    </div>
                    <div class="bg-black/20 p-3.5 rounded border border-[#2c2d30]">
                        <span class="text-gray-500 uppercase tracking-wide text-[10px] font-bold block mb-1">RAM Size</span>
                        <span class="text-white text-sm font-semibold">{{ vm.memory_mb }} MB</span>
                    </div>
                    <div class="bg-black/20 p-3.5 rounded border border-[#2c2d30]">
                        <span class="text-gray-500 uppercase tracking-wide text-[10px] font-bold block mb-1">VNC Port (Host Bind)</span>
                        <span class="text-[#e57300] text-sm font-semibold">127.0.0.1:{{ vm.vnc_port }}</span>
                    </div>
                </div>

                <!-- Graphs wrapper (only query if running) -->
                <div v-if="vm.status === 'running'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- CPU Usage Graph -->
                    <div class="bg-black/10 p-4 border border-[#2c2d30] rounded">
                        <div class="flex items-center justify-between text-xs font-mono mb-2">
                            <span class="text-gray-400 font-semibold">CPU Utilization</span>
                            <span class="text-white font-bold">{{ currentStats.cpu_usage_pct }}%</span>
                        </div>
                        <svg class="w-full h-24 overflow-visible" viewBox="0 0 400 80" preserveAspectRatio="none">
                            <path :d="getSvgAreaPath(cpuHistory, 100)" fill="url(#orange-gradient)" class="opacity-15" />
                            <path :d="getSvgPath(cpuHistory, 100)" fill="none" stroke="#e57300" stroke-width="1.5" />
                        </svg>
                        <div class="flex justify-between text-[9px] text-gray-500 font-mono mt-1 select-none">
                            <span>-40s</span>
                            <span>Now</span>
                        </div>
                    </div>

                    <!-- RAM Usage Graph -->
                    <div class="bg-black/10 p-4 border border-[#2c2d30] rounded">
                        <div class="flex items-center justify-between text-xs font-mono mb-2">
                            <span class="text-gray-400 font-semibold">Memory Usage</span>
                            <span class="text-white font-bold">{{ currentStats.memory_usage_mb }} MB ({{ currentStats.memory_usage_pct }}%)</span>
                        </div>
                        <svg class="w-full h-24 overflow-visible" viewBox="0 0 400 80" preserveAspectRatio="none">
                            <path :d="getSvgAreaPath(memHistory, 100)" fill="url(#orange-gradient)" class="opacity-15" />
                            <path :d="getSvgPath(memHistory, 100)" fill="none" stroke="#e57300" stroke-width="1.5" />
                        </svg>
                        <div class="flex justify-between text-[9px] text-gray-500 font-mono mt-1 select-none">
                            <span>-40s</span>
                            <span>Now</span>
                        </div>
                    </div>

                    <!-- Disk IO Graph -->
                    <div class="bg-black/10 p-4 border border-[#2c2d30] rounded">
                        <div class="flex items-center justify-between text-xs font-mono mb-2">
                            <span class="text-gray-400 font-semibold">Disk IO Rates</span>
                            <span class="text-white font-bold flex space-x-3">
                                <span>R: {{ currentStats.disk_read_kbps }} KB/s</span>
                                <span>W: {{ currentStats.disk_write_kbps }} KB/s</span>
                            </span>
                        </div>
                        <svg class="w-full h-24 overflow-visible" viewBox="0 0 400 80" preserveAspectRatio="none">
                            <path :d="getSvgPath(diskReadHistory, 10000)" fill="none" stroke="#60a5fa" stroke-width="1.2" />
                            <path :d="getSvgPath(diskWriteHistory, 10000)" fill="none" stroke="#e57300" stroke-width="1.2" />
                        </svg>
                        <div class="flex justify-between text-[9px] text-gray-500 font-mono mt-1 select-none">
                            <span>-40s</span>
                            <span class="flex space-x-2">
                                <span class="text-[#60a5fa]">● Read</span>
                                <span class="text-[#e57300]">● Write</span>
                            </span>
                        </div>
                    </div>

                    <!-- Net TX RX Graph -->
                    <div class="bg-black/10 p-4 border border-[#2c2d30] rounded">
                        <div class="flex items-center justify-between text-xs font-mono mb-2">
                            <span class="text-gray-400 font-semibold">Network Throughput</span>
                            <span class="text-white font-bold flex space-x-3">
                                <span>RX: {{ currentStats.net_rx_kbps }} KB/s</span>
                                <span>TX: {{ currentStats.net_tx_kbps }} KB/s</span>
                            </span>
                        </div>
                        <svg class="w-full h-24 overflow-visible" viewBox="0 0 400 80" preserveAspectRatio="none">
                            <path :d="getSvgPath(netRxHistory, 5000)" fill="none" stroke="#10b981" stroke-width="1.2" />
                            <path :d="getSvgPath(netTxHistory, 5000)" fill="none" stroke="#d97706" stroke-width="1.2" />
                        </svg>
                        <div class="flex justify-between text-[9px] text-gray-500 font-mono mt-1 select-none">
                            <span>-40s</span>
                            <span class="flex space-x-2">
                                <span class="text-[#10b981]">● RX (In)</span>
                                <span class="text-[#d97706]">● TX (Out)</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div v-else class="flex flex-col items-center justify-center p-8 bg-black/10 rounded border border-[#2c2d30] border-dashed text-gray-500 text-xs">
                    <svg class="w-10 h-10 mb-2 text-gray-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    <span>Virtual Machine is offline. Realtime resource utilization graphs require the VM to be running.</span>
                </div>
            </div>

            <!-- Hardware Configuration Tab -->
            <div v-show="activeTab === 'hardware'" class="space-y-4">
                <div class="flex items-center justify-between select-none pb-2 border-b border-[#2c2d30] text-xs">
                    <h3 class="text-gray-300 font-bold uppercase">Hardware Resources</h3>
                    <span class="text-[10px] text-gray-500">libvirt settings</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono border-collapse divide-y divide-[#2c2d30]">
                        <thead>
                            <tr class="text-gray-400 select-none">
                                <th class="py-2.5 pr-4 font-semibold">Device</th>
                                <th class="py-2.5 pr-4 font-semibold">Description / Config Value</th>
                                <th class="py-2.5 text-right font-semibold">Virtual Address</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#2c2d30]/60">
                            <tr>
                                <td class="py-2.5 pr-4 font-semibold text-gray-200">Processors</td>
                                <td class="py-2.5 pr-4 text-gray-400">{{ vm.vcpus }} vCPU Core(s) (QEMU KVM machine emulation)</td>
                                <td class="py-2.5 text-right text-gray-500">vcpu0..{{ vm.vcpus - 1 }}</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 pr-4 font-semibold text-gray-200">Memory</td>
                                <td class="py-2.5 pr-4 text-gray-400">{{ vm.memory_mb }} MB Allocated (Host limits applied)</td>
                                <td class="py-2.5 text-right text-gray-500">0x00000000..</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 pr-4 font-semibold text-gray-200">Hard Disk</td>
                                <td class="py-2.5 pr-4 text-gray-400">{{ vm.disk_gb ?? 50 }} GB SCSI VirtIO Disk (qcow2 format)</td>
                                <td class="py-2.5 text-right text-gray-500">vda</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 pr-4 font-semibold text-gray-200">Network Interface</td>
                                <td class="py-2.5 pr-4 text-gray-400">Model: {{ vm.network_model || 'virtio' }}, Source: default, MAC: {{ vm.mac_address || 'Not assigned' }}</td>
                                <td class="py-2.5 text-right text-gray-500">{{ vm.ip_address || 'dhcp' }}</td>
                            </tr>
                            <tr>
                                <td class="py-2.5 pr-4 font-semibold text-gray-200">CD-ROM Drive</td>
                                <td class="py-2.5 pr-4 text-gray-400 italic text-gray-500">No ISO Mounted</td>
                                <td class="py-2.5 text-right text-gray-500">hda</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- VNC Console Tab -->
            <div v-show="activeTab === 'console'" class="space-y-4 flex flex-col items-center">
                <div class="w-full flex items-center justify-between border-b border-[#2c2d30] pb-2 text-xs">
                    <h3 class="text-gray-300 font-bold uppercase">noVNC Interactive Canvas</h3>
                    <span class="bg-[#e57300]/10 text-[#e57300] px-2 py-0.5 rounded text-[10px] uppercase font-bold border border-[#e57300]/25">
                        VNC Port: {{ vm.vnc_port }}
                    </span>
                </div>
                
                <!-- Mock Console Container -->
                <div class="w-full max-w-3xl bg-black border border-[#2c2d30] rounded overflow-hidden aspect-video flex flex-col items-center justify-center p-6 text-center text-xs font-mono text-gray-400">
                    <div v-if="vm.status === 'running'" class="space-y-4">
                        <div class="flex justify-center">
                            <svg class="w-12 h-12 text-[#e57300] animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                        </div>
                        <p class="text-white font-semibold">Ready to connect to noVNC session</p>
                        <p class="text-[11px] text-gray-500 max-w-md mx-auto">
                            WebSocket proxy ready. Real integration connects to websockify at <code class="text-orange-400">ws://&lt;host&gt;:6080/websockify?token={{ vm.uuid.substring(0,8) }}</code> to stream the VNC display from port {{ vm.vnc_port }}.
                        </p>
                        <button disabled class="bg-[#e57300] hover:bg-orange-600 text-black font-semibold font-sans px-4 py-2 rounded text-xs transition cursor-not-allowed opacity-50">
                            Launch Console Window (Milestone 2)
                        </button>
                    </div>

                    <div v-else class="space-y-2">
                        <svg class="w-12 h-12 text-gray-700 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                        </svg>
                        <p class="text-rose-500 font-semibold">Console Offline</p>
                        <p class="text-[10px] text-gray-600">Please start the virtual machine to establish a VNC connection.</p>
                    </div>
                </div>
            </div>

            <!-- Notes & Tags Tab -->
            <div v-show="activeTab === 'metadata'" class="space-y-6">
                <div class="border-b border-[#2c2d30] pb-2 text-xs select-none">
                    <h3 class="text-gray-300 font-bold uppercase">VM Attributes & Notes</h3>
                </div>

                <form @submit.prevent="submitMetadata" class="space-y-4 text-xs font-sans">
                    <!-- Tags input -->
                    <div class="space-y-1.5">
                        <label class="block text-gray-400 font-semibold">Tags (Categories)</label>
                        <div class="flex flex-wrap gap-1.5 p-2 bg-black/30 border border-[#2c2d30] rounded min-h-10 items-center">
                            <span v-for="(tag, index) in metadataForm.tags" :key="tag" class="bg-gray-800 text-gray-300 px-2 py-0.5 rounded text-[10px] font-mono border border-gray-700 flex items-center space-x-1.5">
                                <span>{{ tag }}</span>
                                <button type="button" @click="removeTag(index)" class="text-rose-500 hover:text-rose-400 font-extrabold text-[11px]">×</button>
                            </span>
                            
                            <div class="flex items-center space-x-2 ml-auto">
                                <input
                                    v-model="metadataForm.newTag"
                                    type="text"
                                    placeholder="Add tag..."
                                    class="bg-gray-900 border border-gray-700 rounded px-2 py-0.5 text-[10px] text-white focus:outline-none focus:border-[#e57300] font-mono w-28"
                                    @keydown.enter.prevent="addTag"
                                />
                                <button type="button" @click="addTag" class="text-gray-400 hover:text-white font-extrabold font-mono">+</button>
                            </div>
                        </div>
                    </div>

                    <!-- Notes textarea -->
                    <div class="space-y-1.5">
                        <label class="block text-gray-400 font-semibold">Notes / Administrative Description</label>
                        <textarea
                            v-model="metadataForm.notes"
                            rows="6"
                            placeholder="Enter any deployment details, configuration guidelines, IP addresses, credentials, or task notes for this virtual machine..."
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-3 text-white focus:outline-none focus:border-[#e57300] font-mono text-xs focus:ring-1 focus:ring-[#e57300]"
                        ></textarea>
                    </div>

                    <!-- Submit -->
                    <div class="flex items-center justify-between">
                        <span v-if="metadataForm.recentlySuccessful" class="text-emerald-500 font-mono text-[10px]">✓ Notes saved.</span>
                        <button
                            type="submit"
                            :disabled="metadataForm.processing"
                            class="bg-[#e57300] hover:bg-orange-600 text-black font-bold px-4 py-2 rounded text-xs transition ml-auto disabled:opacity-50"
                        >
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>

        </div>

        <!-- Command Audit Preview / Execution Plan Modal -->
        <div v-if="isPlanModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 backdrop-blur-sm p-4 font-sans text-xs">
            <div class="bg-[#1c1d22] border border-[#2c2d30] shadow-2xl rounded max-w-lg w-full overflow-hidden">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-[#2c2d30] bg-[#16171b] flex justify-between items-center select-none text-left">
                    <h3 class="font-bold text-gray-200 uppercase tracking-wider">Command Execution Plan Preview</h3>
                    <button @click="isPlanModalOpen = false" class="text-gray-400 hover:text-white font-bold text-base">&times;</button>
                </div>
                
                <!-- Body -->
                <div class="p-5 space-y-4 font-mono text-left">
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

        <!-- Edit Hardware Modal -->
        <div v-if="isEditModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 backdrop-blur-sm p-4 font-sans text-xs select-none">
            <div class="bg-[#1c1d22] border border-[#2c2d30] shadow-2xl rounded max-w-lg w-full overflow-hidden">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-[#2c2d30] bg-[#16171b] flex justify-between items-center text-left">
                    <h3 class="font-bold text-gray-200 uppercase tracking-wider">Edit Hardware Configuration</h3>
                    <button @click="isEditModalOpen = false" class="text-gray-400 hover:text-white font-bold text-base">&times;</button>
                </div>
                
                <!-- Body -->
                <form @submit.prevent="submitEditForm" class="p-5 space-y-4 text-left">
                    <div class="grid grid-cols-2 gap-4">
                        <!-- vCPUs -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">vCPU Cores</label>
                            <input
                                v-model="editForm.vcpus"
                                type="number"
                                min="1"
                                max="32"
                                required
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] text-xs"
                            />
                            <span v-if="editForm.errors.vcpus" class="text-rose-500 text-[10px]">{{ editForm.errors.vcpus }}</span>
                        </div>

                        <!-- Memory (MB) -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Memory (MB)</label>
                            <input
                                v-model="editForm.memory_mb"
                                type="number"
                                min="512"
                                max="131072"
                                step="512"
                                required
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] text-xs"
                            />
                            <span v-if="editForm.errors.memory_mb" class="text-rose-500 text-[10px]">{{ editForm.errors.memory_mb }}</span>
                        </div>

                        <!-- Disk GB -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Disk Size (GB)</label>
                            <input
                                v-model="editForm.disk_gb"
                                type="number"
                                min="5"
                                max="2000"
                                required
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] text-xs"
                            />
                            <span v-if="editForm.errors.disk_gb" class="text-rose-500 text-[10px]">{{ editForm.errors.disk_gb }}</span>
                        </div>

                        <!-- Boot Type -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Boot Type (Firmware)</label>
                            <select
                                v-model="editForm.boot_type"
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] text-xs"
                            >
                                <option value="bios">BIOS (Legacy)</option>
                                <option value="uefi">UEFI (Modern OVMF)</option>
                            </select>
                            <span v-if="editForm.errors.boot_type" class="text-rose-500 text-[10px]">{{ editForm.errors.boot_type }}</span>
                        </div>

                        <!-- Machine Type -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Machine Type</label>
                            <select
                                v-model="editForm.machine_type"
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] text-xs"
                            >
                                <option value="pc-q35-6.2">pc-q35-6.2 (Default Q35)</option>
                                <option value="i440fx">i440fx (Legacy standard)</option>
                            </select>
                        </div>

                        <!-- Disk Bus -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Disk Controller Bus</label>
                            <select
                                v-model="editForm.disk_bus"
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] text-xs"
                            >
                                <option value="virtio">VirtIO (Fastest)</option>
                                <option value="sata">SATA (Standard)</option>
                                <option value="scsi">SCSI</option>
                                <option value="ide">IDE</option>
                            </select>
                        </div>

                        <!-- Network Bridge -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Network Bridge Device</label>
                            <input
                                v-model="editForm.network_bridge"
                                type="text"
                                required
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] text-xs"
                            />
                        </div>

                        <!-- Network Model -->
                        <div class="space-y-1">
                            <label class="block text-gray-400 font-semibold">Network Adapter Model</label>
                            <select
                                v-model="editForm.network_model"
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] text-xs"
                            >
                                <option value="virtio">VirtIO (Paravirtualized)</option>
                                <option value="e1000">Intel e1000</option>
                                <option value="rtl8139">Realtek rtl8139</option>
                            </select>
                        </div>

                        <!-- ISO Volume (CD-ROM) -->
                        <div class="col-span-2 space-y-1">
                            <label class="block text-gray-400 font-semibold">CD-ROM / Boot ISO Media</label>
                            <select
                                v-model="editForm.iso_volume"
                                class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] text-xs font-mono"
                            >
                                <option value="">[ No ISO Mounted / Empty CD-ROM ]</option>
                                <option v-for="iso in availableISOs" :key="iso" :value="iso">
                                    {{ iso }}
                                </option>
                            </select>
                            <span v-if="editForm.errors.iso_volume" class="text-rose-500 text-[10px]">{{ editForm.errors.iso_volume }}</span>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="space-y-1">
                        <label class="block text-gray-400 font-semibold">VM Description</label>
                        <textarea
                            v-model="editForm.description"
                            rows="2"
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] text-xs"
                        ></textarea>
                    </div>

                    <!-- USB Controller -->
                    <div class="flex flex-col md:flex-row md:items-center gap-4 pt-1">
                        <div class="flex items-center space-x-2">
                            <input
                                v-model="editForm.usb_controller"
                                type="checkbox"
                                id="edit_usb_controller"
                                class="rounded bg-[#111214] border-[#2c2d30] text-[#e57300] focus:ring-[#e57300] focus:ring-opacity-50"
                            />
                            <label for="edit_usb_controller" class="text-gray-300 font-semibold cursor-pointer">Enable USB Controller</label>
                        </div>
                        
                        <div v-if="editForm.usb_controller" class="flex items-center space-x-2 text-xs">
                            <label class="text-gray-400 font-semibold shrink-0">USB Controller Model:</label>
                            <select
                                v-model="editForm.usb_controller_model"
                                class="bg-[#111214] border border-[#2c2d30] rounded p-1.5 text-white focus:outline-none focus:border-[#e57300] text-xs"
                            >
                                <option value="qemu-xhci">USB 3.0 (qemu-xhci - Win 10/11, Linux)</option>
                                <option value="ehci">USB 2.0 (ehci - Standalone for Win 7/XP)</option>
                                <option value="ich9-ehci1">USB 2.0 (ich9-ehci1 - Intel EHCI)</option>
                                <option value="piix3-uhci">USB 1.1 (piix3-uhci - Legacy)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end space-x-2 pt-3 border-t border-[#2c2d30] mt-4">
                        <button
                            type="button"
                            @click="isEditModalOpen = false"
                            class="px-3.5 py-1.5 rounded border border-[#2c2d30] text-gray-400 hover:text-white transition text-xs"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="editForm.processing"
                            class="bg-[#e57300] hover:bg-orange-600 text-black font-bold px-4 py-1.5 rounded transition text-xs disabled:opacity-50"
                        >
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- SVG Gradients definition for sparklines -->
        <svg class="absolute h-0 w-0" width="0" height="0">
            <defs>
                <linearGradient id="orange-gradient" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#e57300" />
                    <stop offset="100%" stop-color="#e57300" stop-opacity="0" />
                </linearGradient>
            </defs>
        </svg>
    </AuthenticatedLayout>
</template>
