import ScrollUpPlugin from 'src/plugin/scroll-up/scroll-up.plugin';

// Extends the core plugin "ScrollUp". Without "extends" the core code would be replaced completely.
export default class SwagExampleScrollUpPlugin extends ScrollUpPlugin {
    init() {
        super.init();

        // custom logic
    }

    destroy() {
        // remove own listeners, then core cleanup (if present)
        super.destroy();
    }
}
