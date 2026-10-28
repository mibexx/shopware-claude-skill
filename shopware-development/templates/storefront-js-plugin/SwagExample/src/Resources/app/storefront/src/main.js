// Pflicht-Einstieg des Plugins. window.PluginManager ist durch das Core-Storefront-JS bereits gesetzt.
const PluginManager = window.PluginManager;

// Async-Registrierung (Lazy-Chunk). Name = Instanz-ID und bestimmt die data-Attribute:
// "SwagExample" -> data-swag-example / data-swag-example-options
PluginManager.register(
    'SwagExample',
    () => import('./swag-example/swag-example.plugin'),
    '[data-swag-example]',
);

// Core-Plugin ueberschreiben (optional). Selektor muss dem der Core-Registrierung entsprechen
// (storefront/Resources/app/storefront/src/main.js: '[data-scroll-up]').
// import SwagExampleScrollUpPlugin from './override/swag-example-scroll-up.plugin';
// PluginManager.override('ScrollUp', SwagExampleScrollUpPlugin, '[data-scroll-up]');

// Core-Plugin entfernen (optional):
// PluginManager.deregister('ScrollUp', '[data-scroll-up]');
