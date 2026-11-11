<script setup lang="ts">
import { router } from '@statamic/cms/inertia';
import { TabList, Tabs, TabTrigger } from '@statamic/cms/ui';
import { computed } from 'vue';
import type { Tab } from '../types';

const props = defineProps<{ tabs: Tab[] }>();

const active = computed(() => props.tabs.find((tab) => tab.active)?.url);

function open(url: string) {
    if (url !== active.value) {
        router.get(url);
    }
}
</script>

<template>
    <Tabs
        v-if="tabs.length > 1"
        :model-value="active"
        class="mb-6"
        @update:model-value="open"
    >
        <TabList>
            <TabTrigger
                v-for="tab in tabs"
                :key="tab.url"
                :name="tab.url"
                :text="tab.title"
            />
        </TabList>
    </Tabs>
</template>
