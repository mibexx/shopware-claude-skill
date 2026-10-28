global.PluginManager = {
    getPluginInstancesFromElement: () => new Map(),
    getPluginInstanceFromElement: () => ({}),
    getPlugin: () => ({ get: () => [] }),
    getPluginInstances: () => new Map(),
    initializePlugins: jest.fn(() => Promise.resolve()),
    initializePluginsInParentElement: jest.fn(() => Promise.resolve()),
};

// The plugin reads PluginBaseClass from window (in the browser plugin.manager.js sets it).
window.PluginBaseClass = require('src/plugin-system/plugin.class').default;
