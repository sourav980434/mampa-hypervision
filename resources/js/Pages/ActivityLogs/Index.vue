<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    logs: Object // Paginated Eloquent results
});

const formatTime = (timeStr) => {
    const date = new Date(timeStr);
    return date.toLocaleString('en-US', {
        year: 'numeric',
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
</script>

<template>
    <Head title="Audit Tasks Logs" />

    <AuthenticatedLayout>
        <template #breadcrumb>Audit Logs</template>

        <div class="bg-[#1c1d22] border border-[#2c2d30] rounded">
            <div class="px-4 py-3 border-b border-[#2c2d30] bg-[#16171b] flex items-center justify-between select-none">
                <h3 class="text-xs font-bold uppercase tracking-wider text-gray-300">Hypervisor Audit Trail Log</h3>
                <span class="text-[10px] text-gray-500 font-mono">Total Log Tasks: {{ logs.total }}</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-xs font-mono">
                    <thead>
                        <tr class="border-b border-[#2c2d30] text-gray-400 bg-black/20 select-none">
                            <th class="p-3">Log ID</th>
                            <th class="p-3">Timestamp</th>
                            <th class="p-3">User</th>
                            <th class="p-3">Action Task</th>
                            <th class="p-3">Parameters</th>
                            <th class="p-3">IP Address</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#2c2d30]">
                        <tr v-for="log in logs.data" :key="log.id" class="hover:bg-[#25262c]/20 transition">
                            <td class="p-3 text-gray-500 font-mono">#{{ log.id }}</td>
                            <td class="p-3 text-gray-400">{{ formatTime(log.created_at) }}</td>
                            <td class="p-3 text-gray-300 font-semibold font-sans">{{ log.user ? log.user.name : 'System' }}</td>
                            <td class="p-3">
                                <span class="text-[#e57300] bg-[#e57300]/10 px-2 py-0.5 rounded border border-[#e57300]/25 font-bold text-[10px] uppercase">
                                    {{ log.action }}
                                </span>
                            </td>
                            <td class="p-3 text-gray-400 max-w-lg truncate" :title="formatLogDetails(log.details)">
                                {{ formatLogDetails(log.details) }}
                            </td>
                            <td class="p-3 text-gray-500">{{ log.ip_address || '-' }}</td>
                        </tr>
                        <tr v-if="logs.data.length === 0">
                            <td colspan="6" class="p-4 text-center text-gray-500 italic">No audit trail records exist.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Custom Dark Pagination Controls -->
            <div v-if="logs.last_page > 1" class="p-3 bg-[#16171b] border-t border-[#2c2d30] flex items-center justify-between font-sans text-xs">
                <span class="text-gray-500 font-mono">
                    Showing {{ logs.from }} to {{ logs.to }} of {{ logs.total }} log tasks
                </span>

                <div class="flex items-center space-x-1 select-none font-mono">
                    <!-- Page Links -->
                    <template v-for="link in logs.links" :key="link.label">
                        <!-- Previous/Next or numbered page -->
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="px-2.5 py-1 rounded border border-[#2c2d30] text-gray-400 hover:text-white hover:bg-gray-800 transition"
                            :class="{ 'bg-[#e57300]/10 text-[#e57300] border-[#e57300]/40 font-bold': link.active }"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="px-2.5 py-1 rounded text-gray-600 border border-transparent cursor-default"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
