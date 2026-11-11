<script setup lang="ts">
import { Head } from '@statamic/cms/inertia';
import { Pipeline, Request } from '@statamic/cms/save-pipeline';
import {
    Button,
    Header,
    PublishContainer,
    PublishTabs,
} from '@statamic/cms/ui';
import { ref, useTemplateRef } from 'vue';
import AreaTabs from '../components/AreaTabs.vue';
import type { Tab } from '../types';

const props = defineProps<{
    blueprint: Record<string, unknown>;
    values: Record<string, unknown>;
    meta: Record<string, unknown>;
    submitUrl: string;
    tabs: Tab[];
}>();

// The same form and save request as Statamic's own addon settings page.
const container = useTemplateRef('container');
const values = ref(props.values);
const errors = ref({});
const saving = ref(false);

function save() {
    new Pipeline()
        .provide({ container, errors, saving })
        .through([new Request(props.submitUrl, 'patch', {})])
        .then(() => Statamic.$toast.success(__('Saved')));
}
</script>

<template>
    <Head :title="[__('Settings'), 'Content MCP']" />

    <div class="max-w-page mx-auto">
        <Header title="Content MCP" icon="ai-chat-spark">
            <template #actions>
                <Button
                    variant="primary"
                    :text="__('Save')"
                    :disabled="saving"
                    @click="save"
                />
            </template>
        </Header>
        <AreaTabs :tabs />

        <PublishContainer
            ref="container"
            name="content_mcp_settings"
            :blueprint
            :meta
            :errors
            as-config
            v-model="values"
        >
            <PublishTabs />
        </PublishContainer>
    </div>
</template>

<style scoped>
/* Long guidelines would push the other settings far below the page. */
:deep(.markdown-fieldtype .CodeMirror-scroll),
:deep(.markdown-fieldtype .markdown-preview) {
    max-height: 24rem;
}

:deep(.markdown-fieldtype .markdown-preview) {
    overflow-y: auto;
}
</style>
