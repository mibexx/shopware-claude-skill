// Mandatory entry point of the plugin. window.PluginManager is already set by the core storefront JS.
const PluginManager = window.PluginManager;

// Async registration (lazy chunk). Name = instance ID and determines the data attributes:
// "SwagExample" -> data-swag-example / data-swag-example-options
PluginManager.register(
    'SwagExample',
    () => import('./swag-example/swag-example.plugin'),
    '[data-swag-example]',
);

// Override a core plugin (optional). The selector must match that of the core registration
// (storefront/Resources/app/storefront/src/main.js: '[data-scroll-up]').
// import SwagExampleScrollUpPlugin from './override/swag-example-scroll-up.plugin';
// PluginManager.override('ScrollUp', SwagExampleScrollUpPlugin, '[data-scroll-up]');

// Remove a core plugin (optional):
// PluginManager.deregister('ScrollUp', '[data-scroll-up]');
