<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    mappings: Array
});

const form = useForm({
    public_port: '',
    internal_ip: '',
    internal_port: '',
    protocol: 'tcp',
    description: ''
});

const isToggling = ref({});
const isDeleting = ref({});
const isTesting = ref({});
const testResults = ref({});

const activePlan = ref(null);
const isPlanModalOpen = ref(false);

/**
 * Seed form using predefined templates.
 */
const applyTemplate = (type) => {
    const existingPorts = props.mappings.map(m => m.public_port);
    
    if (type === 'rdp') {
        form.internal_port = 3389;
        form.protocol = 'tcp';
        form.description = 'Windows RDP Quick Mapping';
        form.public_port = existingPorts.includes(50001) ? Math.max(50001, ...existingPorts) + 1 : 50001;
    } else if (type === 'http') {
        form.internal_port = 80;
        form.protocol = 'tcp';
        form.description = 'Web HTTP App';
        form.public_port = existingPorts.includes(8080) ? Math.max(8080, ...existingPorts) + 1 : 8080;
    } else if (type === 'node') {
        form.internal_port = 3000;
        form.protocol = 'tcp';
        form.description = 'Node.js Application';
        form.public_port = existingPorts.includes(3000) ? Math.max(3000, ...existingPorts) + 1 : 3000;
    } else if (type === 'vite') {
        form.internal_port = 5173;
        form.protocol = 'tcp';
        form.description = 'Vite Dev Server';
        form.public_port = existingPorts.includes(5173) ? Math.max(5173, ...existingPorts) + 1 : 5173;
    } else if (type === 'flask') {
        form.internal_port = 8000;
        form.protocol = 'tcp';
        form.description = 'Python API App';
        form.public_port = existingPorts.includes(8000) ? Math.max(8000, ...existingPorts) + 1 : 8000;
    }
};

/**
 * Handle form submission by requesting an execution plan first.
 */
const submitForm = () => {
    // Basic frontend validations before plan request
    if (form.public_port < 1024 || form.public_port > 65535) {
        form.setError('public_port', 'Public port must be between 1024 and 65535.');
        return;
    }
    if (form.internal_port < 1 || form.internal_port > 65535) {
        form.setError('internal_port', 'Internal port must be between 1 and 65535.');
        return;
    }
    
    requestActionPlan('create', {
        public_port: form.public_port,
        internal_ip: form.internal_ip,
        internal_port: form.internal_port,
        protocol: form.protocol,
        description: form.description
    });
};

/**
 * Request execution plan from API.
 */
const requestActionPlan = async (action, data = {}) => {
    try {
        const queryParams = new URLSearchParams(data).toString();
        const response = await fetch(`/port-forwarding/execution-plan/${action}?${queryParams}`);
        if (response.ok) {
            activePlan.value = await response.json();
            activePlan.value.payload = data;
            isPlanModalOpen.value = true;
        }
    } catch (e) {
        console.error("Failed to load firewall execution plan", e);
    }
};

/**
 * Confirm and dispatch firewall rule command.
 */
const confirmFirewallExecution = () => {
    if (!activePlan.value || activePlan.value.mode === 'readonly') return;
    
    const action = activePlan.value.action;
    const payload = activePlan.value.payload;
    isPlanModalOpen.value = false;
    
    if (action === 'create') {
        form.post('/port-forwarding', {
            onSuccess: () => {
                form.reset();
                activePlan.value = null;
            }
        });
    } else if (action === 'toggle') {
        const id = payload.id;
        isToggling.value[id] = true;
        router.post(`/port-forwarding/${id}/toggle`, {}, {
            preserveScroll: true,
            onFinish: () => {
                isToggling.value[id] = false;
                activePlan.value = null;
            }
        });
    } else if (action === 'delete') {
        const id = payload.id;
        isDeleting.value[id] = true;
        router.delete(`/port-forwarding/${id}`, {
            preserveScroll: true,
            onFinish: () => {
                isDeleting.value[id] = false;
                activePlan.value = null;
            }
        });
    } else if (action === 'reapply') {
        router.post('/port-forwarding/reapply', {}, {
            preserveScroll: true,
            onFinish: () => {
                activePlan.value = null;
            }
        });
    }
};

/**
 * Connectivity Socket Test.
 */
const testConnectivity = async (id) => {
    isTesting.value[id] = true;
    testResults.value[id] = { status: 'testing', message: 'Probing internal socket connection...' };
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const response = await fetch(`/port-forwarding/${id}/test`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            }
        });
        if (response.ok) {
            const data = await response.json();
            testResults.value[id] = {
                status: data.success ? 'success' : 'failed',
                message: data.message
            };
        } else {
            testResults.value[id] = {
                status: 'failed',
                message: 'HTTP error probing target host.'
            };
        }
    } catch (e) {
        testResults.value[id] = {
            status: 'failed',
            message: 'Network error occurred during socket probe.'
        };
    } finally {
        isTesting.value[id] = false;
        // Auto clear after 8 seconds
        setTimeout(() => {
            if (testResults.value[id]?.status !== 'testing') {
                delete testResults.value[id];
            }
        }, 8000);
    }
};
</script>

<template>
    <Head title="Port Forwarding Rules" />

    <AuthenticatedLayout>
        <template #breadcrumb>Port Forwarding & Publish Manager</template>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Add Mapping Rule Form -->
            <div class="bg-[#1c1d22] border border-[#2c2d30] rounded p-4 h-fit font-sans text-xs">
                <div class="border-b border-[#2c2d30] pb-2 mb-3 select-none">
                    <h3 class="text-gray-300 font-bold uppercase">Add Forwarding Rule</h3>
                </div>

                <!-- Quick Templates Panel -->
                <div class="mb-4 bg-black/20 p-2.5 rounded border border-[#2c2d30]/60 space-y-2 select-none">
                    <span class="text-gray-400 font-semibold block text-[10px] uppercase">Quick Templates</span>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" @click="applyTemplate('rdp')" class="bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700 px-2 py-1 rounded text-[9px] font-mono transition">
                            RDP (3389)
                        </button>
                        <button type="button" @click="applyTemplate('http')" class="bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700 px-2 py-1 rounded text-[9px] font-mono transition">
                            HTTP (80)
                        </button>
                        <button type="button" @click="applyTemplate('node')" class="bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700 px-2 py-1 rounded text-[9px] font-mono transition">
                            Node (3000)
                        </button>
                        <button type="button" @click="applyTemplate('vite')" class="bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700 px-2 py-1 rounded text-[9px] font-mono transition">
                            Vite (5173)
                        </button>
                        <button type="button" @click="applyTemplate('flask')" class="bg-gray-800 hover:bg-gray-700 text-gray-200 border border-gray-700 px-2 py-1 rounded text-[9px] font-mono transition">
                            Flask (8000)
                        </button>
                    </div>
                </div>

                <form @submit.prevent="submitForm" class="space-y-4">
                    <!-- Public Port -->
                    <div class="space-y-1">
                        <label class="block text-gray-400 font-semibold">Public Port (External)</label>
                        <input
                            v-model="form.public_port"
                            type="number"
                            placeholder="e.g. 50001"
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] focus:ring-1 focus:ring-[#e57300]"
                            required
                        />
                        <span v-if="form.errors.public_port" class="text-rose-500 text-[10px] font-mono block mt-0.5">{{ form.errors.public_port }}</span>
                    </div>

                    <!-- Protocol -->
                    <div class="space-y-1">
                        <label class="block text-gray-400 font-semibold">Network Protocol</label>
                        <select
                            v-model="form.protocol"
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] focus:ring-1 focus:ring-[#e57300]"
                        >
                            <option value="tcp">TCP (standard web/RDP/VNC)</option>
                            <option value="udp">UDP (DNS/VPN)</option>
                        </select>
                    </div>

                    <!-- Internal IP -->
                    <div class="space-y-1">
                        <label class="block text-gray-400 font-semibold">Internal VM IP Address</label>
                        <input
                            v-model="form.internal_ip"
                            type="text"
                            placeholder="e.g. 192.168.122.212"
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] focus:ring-1 focus:ring-[#e57300]"
                            required
                        />
                        <span v-if="form.errors.internal_ip" class="text-rose-500 text-[10px] font-mono block mt-0.5">{{ form.errors.internal_ip }}</span>
                    </div>

                    <!-- Internal Port -->
                    <div class="space-y-1">
                        <label class="block text-gray-400 font-semibold">Internal VM Port</label>
                        <input
                            v-model="form.internal_port"
                            type="number"
                            placeholder="e.g. 3389 (RDP)"
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white font-mono focus:outline-none focus:border-[#e57300] focus:ring-1 focus:ring-[#e57300]"
                            required
                        />
                        <span v-if="form.errors.internal_port" class="text-rose-500 text-[10px] font-mono block mt-0.5">{{ form.errors.internal_port }}</span>
                    </div>

                    <!-- Description -->
                    <div class="space-y-1">
                        <label class="block text-gray-400 font-semibold">Description</label>
                        <input
                            v-model="form.description"
                            type="text"
                            placeholder="e.g. Forward RDP for Win11 workstation"
                            class="w-full bg-[#111214] border border-[#2c2d30] rounded p-2 text-white focus:outline-none focus:border-[#e57300] focus:ring-1 focus:ring-[#e57300]"
                        />
                    </div>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full bg-[#e57300] hover:bg-orange-600 text-black font-bold p-2.5 rounded text-xs transition disabled:opacity-50"
                    >
                        {{ form.processing ? 'Generating plan...' : 'Apply Forwarding Rule' }}
                    </button>
                </form>
            </div>

            <!-- Active Forwarding Rules Table -->
            <div class="bg-[#1c1d22] border border-[#2c2d30] rounded lg:col-span-2">
                <div class="px-4 py-3 border-b border-[#2c2d30] bg-[#16171b] flex items-center justify-between select-none">
                    <div class="flex items-center space-x-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-gray-300">Port Forwarding Table</h3>
                        <span class="text-[10px] text-gray-500 font-mono">Active Port Count: {{ mappings.filter(m => m.status === 'active').length }}</span>
                    </div>
                    
                    <!-- Recovery re-apply button -->
                    <button
                        @click="requestActionPlan('reapply')"
                        class="bg-gray-800 hover:bg-gray-700 text-gray-300 hover:text-white border border-gray-700 font-sans text-[10px] px-2.5 py-1 rounded transition select-none flex items-center space-x-1.5"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89M9 11l3-3 3 3m-3-3v12" />
                        </svg>
                        <span>Re-apply All Active</span>
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-[#2c2d30] text-gray-400 bg-black/20 select-none">
                                <th class="p-3 font-semibold">Public Port</th>
                                <th class="p-3 font-semibold">Protocol</th>
                                <th class="p-3 font-semibold">Internal Destination</th>
                                <th class="p-3 font-semibold">Description</th>
                                <th class="p-3 font-semibold">Status</th>
                                <th class="p-3 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#2c2d30] font-mono">
                            <template v-for="map in mappings" :key="map.id">
                                <tr class="hover:bg-[#25262c]/20 transition">
                                    <!-- Public Port -->
                                    <td class="p-3 font-bold text-white">:{{ map.public_port }}</td>

                                    <!-- Protocol -->
                                    <td class="p-3">
                                        <span class="bg-[#e57300]/10 text-[#e57300] border border-[#e57300]/30 px-1 py-0.5 rounded text-[10px] font-bold uppercase font-mono">
                                            {{ map.protocol }}
                                        </span>
                                    </td>

                                    <!-- Internal Destination -->
                                    <td class="p-3 text-gray-300">{{ map.internal_ip }}:{{ map.internal_port }}</td>

                                    <!-- Description -->
                                    <td class="p-3 text-gray-400 font-sans max-w-xs truncate" :title="map.description">
                                        {{ map.description || '-' }}
                                    </td>

                                    <!-- Status Toggle -->
                                    <td class="p-3">
                                        <button
                                            @click="requestActionPlan('toggle', { id: map.id })"
                                            :disabled="isToggling[map.id]"
                                            class="inline-flex items-center space-x-1 px-2 py-0.5 rounded text-[10px] uppercase font-bold border transition duration-150 disabled:opacity-50"
                                            :class="map.status === 'active'
                                                ? 'bg-emerald-600/10 hover:bg-rose-600/10 text-emerald-400 hover:text-rose-400 border-emerald-400/20 hover:border-rose-400/20'
                                                : 'bg-rose-600/10 hover:bg-emerald-600/10 text-rose-400 hover:text-emerald-400 border-rose-400/20 hover:border-emerald-400/20'"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                                            <span>{{ map.status }}</span>
                                        </button>
                                    </td>

                                    <!-- Actions -->
                                    <td class="p-3 text-right">
                                        <div class="flex items-center justify-end space-x-1.5">
                                            <!-- Connection Test -->
                                            <button
                                                @click="testConnectivity(map.id)"
                                                :disabled="isTesting[map.id]"
                                                class="bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 hover:text-white font-sans text-[10px] px-2 py-1 rounded transition disabled:opacity-50"
                                            >
                                                {{ isTesting[map.id] ? 'Probing...' : 'Test' }}
                                            </button>

                                            <!-- Delete -->
                                            <button
                                                @click="requestActionPlan('delete', { id: map.id })"
                                                :disabled="isDeleting[map.id]"
                                                class="bg-rose-950/40 hover:bg-rose-900 border border-rose-900/40 hover:border-rose-700/60 text-rose-300 font-sans text-[10px] px-2.5 py-1 rounded transition disabled:opacity-50"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Expandable Test Results Alert box -->
                                <tr v-if="testResults[map.id]" class="bg-[#18191e]/50 font-sans text-[10px] border-none select-none">
                                    <td colspan="6" class="px-4 py-2 border-b border-[#2c2d30]/30">
                                        <div class="flex items-center space-x-2" :class="testResults[map.id].status === 'success' ? 'text-emerald-400' : (testResults[map.id].status === 'testing' ? 'text-amber-400 animate-pulse' : 'text-rose-400')">
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                            <span class="font-mono">{{ testResults[map.id].message }}</span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="mappings.length === 0">
                                <td colspan="6" class="p-4 text-center text-gray-500 italic">No port forwarding mappings registered.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Command Audit Preview / Execution Plan Modal -->
        <div v-if="isPlanModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 backdrop-blur-sm p-4 font-sans text-xs">
            <div class="bg-[#1c1d22] border border-[#2c2d30] shadow-2xl rounded max-w-lg w-full overflow-hidden">
                <!-- Header -->
                <div class="px-5 py-4 border-b border-[#2c2d30] bg-[#16171b] flex justify-between items-center select-none text-left">
                    <h3 class="font-bold text-gray-200 uppercase tracking-wider">Firewall Change Execution Plan</h3>
                    <button @click="isPlanModalOpen = false" class="text-gray-400 hover:text-white font-bold text-base">&times;</button>
                </div>
                
                <!-- Body -->
                <div class="p-5 space-y-4 font-mono text-left">
                    <div class="grid grid-cols-3 gap-2 border-b border-[#2c2d30]/60 pb-3">
                        <span class="text-gray-500 font-sans">Action Type:</span>
                        <span class="col-span-2 text-white font-semibold uppercase">{{ activePlan.action }}</span>

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
                        <span class="text-gray-500 font-sans block">Raw iptables command(s) to execute:</span>
                        <pre class="bg-black/50 border border-[#2c2d30] p-2.5 rounded text-[#e57300] font-semibold break-all text-[10px] whitespace-pre-wrap font-mono">{{ activePlan.command }}</pre>
                    </div>

                    <!-- Risk, Expected and Rollback info -->
                    <div class="grid grid-cols-3 gap-y-3 gap-x-2 pt-2 border-t border-[#2c2d30]/60 text-[11px]">
                        <span class="text-gray-500 font-sans">Risk Level:</span>
                        <span class="col-span-2 font-sans font-semibold text-amber-400">
                            ● {{ activePlan.risk_level }} RISK (Modifies network routing)
                        </span>

                        <span class="text-gray-500 font-sans">Expected Result:</span>
                        <span class="col-span-2 text-gray-300 font-sans">{{ activePlan.expected_result }}</span>

                        <span class="text-gray-500 font-sans">Rollback Option:</span>
                        <span class="col-span-2 text-gray-400 font-sans">{{ activePlan.rollback_option }}</span>
                    </div>

                    <!-- Warning if readonly -->
                    <div v-if="activePlan.mode === 'readonly'" class="bg-rose-500/10 border border-rose-500/25 p-3 rounded text-rose-300 font-sans leading-relaxed text-[11px]">
                        <strong>Block Alert:</strong> Firewall modifications cannot be executed because the hypervisor is operating under a global read-only policy. Set <code class="bg-black/40 px-1 rounded text-orange-400">HYPERVISOR_MODE=active</code> in `.env` to execute.
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
                        @click="confirmFirewallExecution"
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
    </AuthenticatedLayout>
</template>
