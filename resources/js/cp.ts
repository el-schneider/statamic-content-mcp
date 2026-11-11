import Connect from './pages/Connect.vue';
import Connections from './pages/Connections.vue';
import Settings from './pages/Settings.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('content-mcp::Connect', Connect);
    Statamic.$inertia.register('content-mcp::Connections', Connections);
    Statamic.$inertia.register('content-mcp::Settings', Settings);
});
