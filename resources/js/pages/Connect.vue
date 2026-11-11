<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import { CardPanel, Description, Header, Input } from '@statamic/cms/ui';
import AreaTabs from '../components/AreaTabs.vue';
import ConnectionsListing from '../components/ConnectionsListing.vue';
import type { Connection, Tab } from '../types';

defineProps<{
    endpoint: string;
    deletes: boolean;
    connections: Connection[];
    tabs: Tab[];
}>();
</script>

<template>
    <Head :title="[__('Connect'), 'Content MCP']" />

    <div class="max-w-page mx-auto">
        <Header title="Content MCP" icon="ai-chat-spark" />
        <AreaTabs :tabs />

        <CardPanel :heading="__('Connect your AI assistant')" class="mb-6">
            <Input
                :model-value="endpoint"
                :aria-label="__('Server URL')"
                read-only
                copyable
            />
            <!-- Inline: the CP's stylesheet only has the utility classes Statamic uses itself. -->
            <ol
                class="mt-4 space-y-1"
                style="list-style: decimal; padding-inline-start: 1.25rem"
            >
                <li
                    v-text="
                        __(
                            'In your AI assistant, add a custom connector or remote MCP server.',
                        )
                    "
                />
                <li v-text="__('Paste the URL above.')" />
                <li
                    v-text="
                        __(
                            'Sign in with your Control Panel account and approve the connection.',
                        )
                    "
                />
            </ol>
        </CardPanel>

        <CardPanel :heading="__('What the assistant may do')" class="mb-6">
            <Description
                :text="
                    __(
                        'Everything you may do with content in the Control Panel, and nothing more: read and edit entries, globals, terms, navigations and assets. New entries are always drafts, and it publishes only when you ask it to.',
                    )
                "
            />
            <Description
                class="mt-2"
                :text="
                    deletes
                        ? __(
                              'Deleting is turned on: the assistant may delete what you may delete.',
                          )
                        : __('Deleting is turned off on this site.')
                "
            />
        </CardPanel>

        <CardPanel :heading="__('Your connections')">
            <ConnectionsListing
                v-if="connections.length"
                :connections
                :show-user="false"
            />
            <Description
                v-else
                :text="__('You have not connected an app yet.')"
            />
        </CardPanel>
    </div>
</template>
