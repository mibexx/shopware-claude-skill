global.PluginManager = {
    getPluginInstancesFromElement: () => new Map(),
    getPluginInstanceFromElement: () => ({}),
    getPlugin: () => ({ get: () => [] }),
    getPluginInstances: () => new Map(),
    initializePlugins: jest.fn(() => Promise.resolve()),
    initializePluginsInParentElement: jest.fn(() => Promise.resolve()),
};

// Das Plugin liest PluginBaseClass aus window (im Browser setzt plugin.manager.js das).
window.PluginBaseClass = require('src/plugin-system/plugin.class').default;
