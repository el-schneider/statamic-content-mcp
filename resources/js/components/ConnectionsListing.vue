<script setup lang="ts">
import { router } from '@statamic/cms/inertia';
import {
    Badge,
    ConfirmationModal,
    DropdownItem,
    Listing,
} from '@statamic/cms/ui';
import { computed, ref } from 'vue';
import type { Connection } from '../types';

const props = defineProps<{
    connections: Connection[];
    showUser: boolean;
}>();

const columns = computed(() => [
    { label: __('App'), field: 'app' },
    ...(props.showUser ? [{ label: __('User'), field: 'user' }] : []),
    { label: __('First connected'), field: 'first_connected_at' },
    { label: __('Last token issued'), field: 'last_token_issued_at' },
    { label: __('Status'), field: 'state' },
]);

const states = {
    active: { text: __('Active'), color: 'green' },
    idle: { text: __('Idle, renews on next use'), color: 'default' },
    expired: { text: __('Expired'), color: 'amber' },
} as const;

const disconnecting = ref<Connection | null>(null);
const busy = ref(false);

function disconnect() {
    if (disconnecting.value === null) {
        return;
    }

    busy.value = true;
    router.delete(disconnecting.value.disconnect_url, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            disconnecting.value = null;
        },
    });
}
</script>

<template>
    <Listing
        :items="connections"
        :columns
        :allow-search="false"
        :allow-customizing-columns="false"
    >
        <template #cell-app="{ row }">
            <div class="flex flex-col">
                <span v-text="row.app" />
                <span
                    v-if="row.redirect_host"
                    class="text-sm text-gray-600 dark:text-gray-400"
                    v-text="row.redirect_host"
                />
            </div>
        </template>
        <template #cell-user="{ row }">
            <span v-if="row.user" v-text="row.user.name || row.user.email" />
            <span
                v-else
                class="text-gray-600 dark:text-gray-400"
                v-text="__('Deleted user (:id)', { id: row.user_id })"
            />
        </template>
        <template #cell-first_connected_at="{ value }">
            <date-time :of="value" />
        </template>
        <template #cell-last_token_issued_at="{ value }">
            <date-time :of="value" :options="{ relative: true }" />
        </template>
        <template #cell-state="{ row }">
            <Badge
                :text="states[row.state as Connection['state']].text"
                :color="states[row.state as Connection['state']].color"
            />
        </template>
        <template #prepended-row-actions="{ row }">
            <DropdownItem
                :text="__('Disconnect')"
                icon="trash"
                variant="destructive"
                @click="disconnecting = row as Connection"
            />
        </template>
    </Listing>

    <ConfirmationModal
        :open="disconnecting !== null"
        :title="__('Disconnect :app?', { app: disconnecting?.app ?? '' })"
        :body-text="
            __(
                'The app loses access at once. To use it again, it has to connect and be approved again.',
            )
        "
        :button-text="__('Disconnect')"
        :busy
        danger
        @update:open="disconnecting = $event ? disconnecting : null"
        @confirm="disconnect"
    />
</template>
