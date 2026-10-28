import ScrollUpPlugin from 'src/plugin/scroll-up/scroll-up.plugin';

// Erweitert das Core-Plugin "ScrollUp". Ohne "extends" wuerde der Core-Code komplett ersetzt.
export default class SwagExampleScrollUpPlugin extends ScrollUpPlugin {
    init() {
        super.init();

        // eigene Logik
    }

    destroy() {
        // eigene Listener entfernen, dann Core-Aufraeumen (falls vorhanden)
        super.destroy();
    }
}
