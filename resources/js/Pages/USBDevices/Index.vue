<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, computed } from 'vue';
import axios from 'axios';

const page = usePage();
const user = computed(() => page.props.auth.user);
const globalVms = computed(() => page.props.vms || []);

// Running VMs list for dropdown
const runningVms = computed(() => {
    return globalVms.value.filter(vm => vm.status === 'running');
});

const devices = ref([]);
const attachedState = ref({});
const previousDevices = ref([]);
const isLoading = ref(true);
const isActionLoading = ref({});
const toasts = ref([]);

// Modal state
const isAttachModalOpen = ref(false);
const selectedDevice = ref(null);
const selectedVmUuid = ref('');

// Auto-refresh interval
let refreshInterval = null;

// Add a toast notification
const addToast = (message, type = 'success') => {
    const id = Date.now() + Math.random();
    toasts.value.push({ id, message, type });
    setTimeout(() => {
        toasts.value = toasts.value.filter(t => t.id !== id);
    }, 5000);
};

// Fetch all USB devices on host
const fetchDevices = async (showLoading = false) => {
    if (showLoading) isLoading.value = true;
    try {
        const response = await axios.get('/api/usb/devices');
        const data = response.data;
        
        // Detect if any USB device was plugged in or removed
        detectHotplugChanges(data);
        
        devices.value = data;
    } catch (e) {
        console.error("Failed to fetch USB devices", e);
    } finally {
        if (showLoading) isLoading.value = false;
    }
};

// Fetch current attachment status
const fetchAttachedState = async () => {
    try {
        const response = await axios.get('/api/usb/attached');
        attachedState.value = response.data;
    } catch (e) {
        console.error("Failed to fetch attached USB states", e);
    }
};

// Polling data refresh
const refreshData = async () => {
    await Promise.all([fetchDevices(false), fetchAttachedState()]);
};

// Hotplug alerts
const detectHotplugChanges = (newDevices) => {
    if (previousDevices.value.length > 0) {
        // Find disconnected
        previousDevices.value.forEach(oldDev => {
            const stillExists = newDevices.some(newDev => 
                newDev.vendor_id === oldDev.vendor_id && 
                newDev.product_id === oldDev.product_id
            );
            if (!stillExists) {
                addToast(`USB Device disconnected: ${oldDev.manufacturer} ${oldDev.product_name}`, 'error');
            }
        });
        
        // Find connected
        newDevices.forEach(newDev => {
            const wasPresent = previousDevices.value.some(oldDev => 
                oldDev.vendor_id === newDev.vendor_id && 
                oldDev.product_id === newDev.product_id
            );
            if (!wasPresent) {
                addToast(`USB Device connected: ${newDev.manufacturer} ${newDev.product_name}`, 'success');
            }
        });
    }
    previousDevices.value = JSON.parse(JSON.stringify(newDevices));
};

// Open attachment modal
const openAttachModal = (device) => {
    if (user.value.role !== 'admin') return;
    selectedDevice.value = device;
    selectedVmUuid.value = runningVms.value.length > 0 ? runningVms.value[0].uuid : '';
    isAttachModalOpen.value = true;
};

// Close attachment modal
const closeAttachModal = () => {
    isAttachModalOpen.value = false;
    selectedDevice.value = null;
    selectedVmUuid.value = '';
};

// Handle attach command
const handleAttach = async () => {
    if (!selectedDevice.value || !selectedVmUuid.value) return;
    
    const key = `${selectedDevice.value.vendor_id}:${selectedDevice.value.product_id}`;
    isActionLoading.value[key] = true;
    isAttachModalOpen.value = false;

    try {
        const response = await axios.post('/api/usb/attach', {
            vm_uuid: selectedVmUuid.value,
            vendor_id: selectedDevice.value.vendor_id,
            product_id: selectedDevice.value.product_id
        });

        const result = response.data;
        if (result.success) {
            addToast(result.message || 'USB attached successfully.', 'success');
            await refreshData();
        } else {
            addToast(result.message || 'Failed to attach USB.', 'error');
        }
    } catch (e) {
        const errorMsg = e.response?.data?.message || 'Network error during USB attachment.';
        addToast(errorMsg, 'error');
    } finally {
        delete isActionLoading.value[key];
        closeAttachModal();
    }
};

// Handle detach command
const handleDetach = async (device) => {
    if (user.value.role !== 'admin') return;
    
    const key = `${device.vendor_id}:${device.product_id}`;
    const attachedVm = attachedState.value[key];
    if (!attachedVm) return;

    if (!confirm(`Are you sure you want to detach this USB device from VM '${attachedVm.vm_name}'?`)) {
        return;
    }

    isActionLoading.value[key] = true;

    try {
        const response = await axios.post('/api/usb/detach', {
            vm_uuid: attachedVm.vm_uuid,
            vendor_id: device.vendor_id,
            product_id: device.product_id
        });

        const result = response.data;
        if (result.success) {
            addToast(result.message || 'USB detached successfully.', 'success');
            await refreshData();
        } else {
            addToast(result.message || 'Failed to detach USB.', 'error');
        }
    } catch (e) {
        const errorMsg = e.response?.data?.message || 'Network error during USB detachment.';
        addToast(errorMsg, 'error');
    } finally {
        delete isActionLoading.value[key];
    }
};

// Lifecycle Hooks
onMounted(async () => {
    await Promise.all([fetchDevices(true), fetchAttachedState()]);
    
    // Auto-refresh every 5 seconds
    refreshInterval = setInterval(refreshData, 5000);
});

onUnmounted(() => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});
</script>

<template>
    <Head title="USB Device Passthrough" />

    <AuthenticatedLayout>
        <template #breadcrumb>USB Device Management</template>

        <!-- Main Panel Header -->
        <div class="bg-[#1c1d22] border border-[#2c2d30] rounded">
            <div class="px-4 py-3 border-b border-[#2c2d30] bg-[#16171b] flex items-center justify-between select-none">
                <div class="flex items-center space-x-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-gray-300">Physical Host USB Devices</h3>
                    <span class="text-[10px] bg-[#e57300]/10 text-[#e57300] border border-[#e57300]/25 px-1.5 py-0.5 rounded font-mono font-bold">
                        PASSTHROUGH READY
                    </span>
                </div>
                <div class="flex items-center space-x-2 font-mono text-[10px] text-gray-500">
                    <span class="relative flex h-1.5 w-1.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-emerald-500"></span>
                    </span>
                    <span>Auto-refresh active (5s)</span>
                </div>
            </div>

            <!-- Devices Table -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-[#2c2d30] text-gray-400 bg-black/20 select-none">
                            <th class="p-3 font-semibold w-16">Status</th>
                            <th class="p-3 font-semibold">USB Name</th>
                            <th class="p-3 font-semibold">Manufacturer</th>
                            <th class="p-3 font-semibold">Vendor ID</th>
                            <th class="p-3 font-semibold">Product ID</th>
                            <th class="p-3 font-semibold">Attached VM</th>
                            <th class="p-3 font-semibold text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#2c2d30] font-mono">
                        <tr v-if="isLoading">
                            <td colspan="7" class="p-8 text-center text-gray-400 font-sans">
                                <svg class="animate-spin h-5 w-5 mx-auto mb-2 text-[#e57300]" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Loading host USB controller...
                            </td>
                        </tr>
                        
                        <tr v-else-if="devices.length === 0">
                            <td colspan="7" class="p-8 text-center text-gray-500 italic font-sans">
                                No physical USB devices detected on host.
                            </td>
                        </tr>

                        <tr v-else v-for="dev in devices" :key="`${dev.vendor_id}:${dev.product_id}`" class="hover:bg-[#25262c]/20 transition">
                            <!-- Status Indicator -->
                            <td class="p-3">
                                <span class="flex items-center justify-center">
                                    <span 
                                        class="h-2 w-2 rounded-full"
                                        :class="attachedState[`${dev.vendor_id}:${dev.product_id}`] ? 'bg-emerald-500 animate-pulse' : 'bg-gray-500'"
                                        :title="attachedState[`${dev.vendor_id}:${dev.product_id}`] ? 'Attached' : 'Available'"
                                    ></span>
                                </span>
                            </td>

                            <!-- USB Name -->
                            <td class="p-3 font-sans text-white font-semibold">
                                {{ dev.manufacturer }} {{ dev.product_name }}
                            </td>

                            <!-- Manufacturer -->
                            <td class="p-3 text-gray-300 font-sans">
                                {{ dev.manufacturer }}
                            </td>

                            <!-- Vendor ID -->
                            <td class="p-3 text-gray-400 font-mono">
                                {{ dev.vendor_id }}
                            </td>

                            <!-- Product ID -->
                            <td class="p-3 text-gray-400 font-mono">
                                {{ dev.product_id }}
                            </td>

                            <!-- Attached VM -->
                            <td class="p-3">
                                <div v-if="attachedState[`${dev.vendor_id}:${dev.product_id}`]" class="flex items-center space-x-1.5">
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] uppercase font-bold bg-[#e57300]/10 text-[#e57300] border border-[#e57300]/20 font-sans">
                                        Attached to: {{ attachedState[`${dev.vendor_id}:${dev.product_id}`].vm_name }}
                                    </span>
                                </div>
                                <span v-else class="text-gray-500 font-sans text-[11px]">Available</span>
                            </td>

                            <!-- Action -->
                            <td class="p-3 text-right">
                                <div v-if="attachedState[`${dev.vendor_id}:${dev.product_id}`]">
                                    <button
                                        @click="handleDetach(dev)"
                                        :disabled="user.role !== 'admin' || isActionLoading[`${dev.vendor_id}:${dev.product_id}`]"
                                        class="bg-rose-950/40 hover:bg-rose-900 border border-rose-900/40 hover:border-rose-700/60 text-rose-300 font-sans text-[10px] px-3 py-1 rounded transition disabled:opacity-30 disabled:cursor-not-allowed select-none"
                                    >
                                        {{ isActionLoading[`${dev.vendor_id}:${dev.product_id}`] ? 'Detaching...' : 'Detach' }}
                                    </button>
                                </div>
                                <div v-else>
                                    <button
                                        @click="openAttachModal(dev)"
                                        :disabled="user.role !== 'admin' || runningVms.length === 0 || isActionLoading[`${dev.vendor_id}:${dev.product_id}`]"
                                        class="bg-[#e57300] hover:bg-orange-600 text-black font-bold font-sans text-[10px] px-3.5 py-1 rounded transition disabled:opacity-30 disabled:cursor-not-allowed select-none"
                                        :title="runningVms.length === 0 ? 'No running virtual machines to attach to.' : ''"
                                    >
                                        {{ isActionLoading[`${dev.vendor_id}:${dev.product_id}`] ? 'Attaching...' : 'Attach' }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer Details -->
            <div class="p-3 bg-[#16171b] border-t border-[#2c2d30] text-[10px] text-gray-500 font-sans flex justify-between items-center select-none">
                <span>Security Notice: Attachments require VM authorization. Only administrators can perform device mapping.</span>
                <span class="font-mono">Driver: LocalLibvirtDriver (lsusb helper)</span>
            </div>
        </div>

        <!-- Attachment Modal -->
        <div v-if="isAttachModalOpen && selectedDevice" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 backdrop-blur-sm p-4 font-sans text-xs">
            <div class="bg-[#1c1d22] border border-[#2c2d30] shadow-2xl rounded max-w-md w-full overflow-hidden">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-[#2c2d30] bg-[#16171b] flex justify-between items-center select-none">
                    <h3 class="font-bold text-gray-200 uppercase tracking-wider">Attach USB Device</h3>
                    <button @click="closeAttachModal" class="text-gray-400 hover:text-white font-bold text-base">&times;</button>
                </div>

                <!-- Body -->
                <div class="p-5 space-y-4">
                    <!-- Device Description -->
                    <div class="bg-black/30 border border-[#2c2d30]/80 p-3 rounded space-y-1">
                        <span class="text-[10px] text-gray-500 uppercase font-semibold">Device Info</span>
                        <div class="text-white font-semibold font-mono text-[13px]">
                            {{ selectedDevice.manufacturer }} {{ selectedDevice.product_name }}
                        </div>
                        <div class="text-gray-400 font-mono text-[10px] flex space-x-4">
                            <span>Vendor ID: {{ selectedDevice.vendor_id }}</span>
                            <span>Product ID: {{ selectedDevice.product_id }}</span>
                            <span>Bus/Dev: {{ selectedDevice.bus }}/{{ selectedDevice.device_number }}</span>
                        </div>
                    </div>

                    <!-- VM Selection Dropdown -->
                    <div class="space-y-1.5 text-left">
                        <label class="block text-gray-300 font-semibold">Select Running Target VM</label>
                        <select 
                            v-model="selectedVmUuid"
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2.5 text-white focus:outline-none focus:border-[#e57300]"
                        >
                            <option v-for="vm in runningVms" :key="vm.uuid" :value="vm.uuid">
                                {{ vm.name }}
                            </option>
                        </select>
                        <span v-if="runningVms.length === 0" class="text-rose-400 text-[10px] block font-mono">
                            ⚠️ Error: No virtual machines are currently running. You must start a VM first to attach USBs.
                        </span>
                    </div>

                    <!-- Future Expansion Placeholder Notice -->
                    <div class="text-[10px] text-gray-500 leading-relaxed border-t border-[#2c2d30]/50 pt-3">
                        Note: The device will be hot-plugged directly into the guest OS live. Supported configurations include USB 2.0 / USB 3.0 passthrough.
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-5 py-3.5 border-t border-[#2c2d30] bg-[#16171b] flex justify-end space-x-2">
                    <button
                        @click="closeAttachModal"
                        class="px-3.5 py-1.5 rounded border border-[#2c2d30] text-gray-400 hover:text-white transition text-xs"
                    >
                        Cancel
                    </button>
                    <button
                        @click="handleAttach"
                        :disabled="!selectedVmUuid"
                        class="bg-[#e57300] hover:bg-orange-600 text-black font-bold px-4 py-1.5 rounded transition text-xs disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Attach Device
                    </button>
                </div>
            </div>
        </div>

        <!-- Custom Toast Notifications Overlay -->
        <div class="fixed bottom-4 right-4 z-50 space-y-2 font-sans select-none max-w-sm w-full">
            <div 
                v-for="toast in toasts" 
                :key="toast.id" 
                class="p-3.5 rounded border shadow-2xl text-white text-xs flex items-start space-x-2.5 transition duration-300 transform translate-y-0"
                :class="toast.type === 'success' ? 'bg-[#1b3f2a] border-emerald-500/35 text-emerald-100' : 'bg-[#4a1c22] border-rose-500/35 text-rose-100'"
            >
                <!-- Alert Icon -->
                <svg v-if="toast.type === 'success'" class="w-4 h-4 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <svg v-else class="w-4 h-4 text-rose-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                
                <div class="flex-1">
                    <div class="font-bold text-[11px] mb-0.5 uppercase tracking-wider" :class="toast.type === 'success' ? 'text-emerald-400' : 'text-rose-400'">
                        {{ toast.type === 'success' ? 'System Notification' : 'Error Alert' }}
                    </div>
                    <div>{{ toast.message }}</div>
                </div>
                
                <button 
                    @click="toasts = toasts.filter(t => t.id !== toast.id)" 
                    class="text-gray-400 hover:text-white font-semibold transition"
                >
                    &times;
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
