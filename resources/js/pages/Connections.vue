<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import { Description, Header } from '@statamic/cms/ui';
import AreaTabs from '../components/AreaTabs.vue';
import ConnectionsListing from '../components/ConnectionsListing.vue';
import type { Connection, Tab } from '../types';

defineProps<{ connections: Connection[]; tabs: Tab[] }>();
</script>

<template>
    <Head :title="[__('Connections'), 'Content MCP']" />

    <div class="max-w-page mx-auto">
        <Header title="Content MCP" icon="ai-chat-spark" />
        <AreaTabs :tabs />

        <Description
            class="mb-4"
            :text="
                __(
                    'Every app connected for an account on this site. “Access last renewed” shows when the app last renewed its access, which it does about once an hour while it is in use. Single uses are not recorded.',
                )
            "
        />
        <ConnectionsListing
            v-if="connections.length"
            :connections
            :show-user="true"
        />
        <Description v-else :text="__('No app is connected.')" />
    </div>
</template>
