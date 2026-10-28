import './acl';

// Komponenten muessen registriert sein, bevor das Modul sie in "routes" nennt (Lazy-Form wie im Core)
Shopware.Component.register('swag-example-list', () => import('./page/swag-example-list'));
Shopware.Component.register('swag-example-detail', () => import('./page/swag-example-detail'));

// Modul-ID: <namespace>-<name> (mind. ein Bindestrich). Daraus entstehen die Route-Namen swag.example.<key>
Shopware.Module.register('swag-example', {
    type: 'plugin',
    name: 'swag-example',
    title: 'swag-example.general.mainMenuItemGeneral',
    description: 'swag-example.general.descriptionTextModule',
    color: '#ff3d58',
    icon: 'regular-shopping-bag',

    routes: {
        index: {
            component: 'swag-example-list',
            path: 'index',
            meta: {
                privilege: 'swag_example.viewer',
            },
        },
        create: {
            component: 'swag-example-detail',
            path: 'create',
            meta: {
                parentPath: 'swag.example.index',
                privilege: 'swag_example.creator',
            },
        },
        detail: {
            component: 'swag-example-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'swag.example.index',
                privilege: 'swag_example.viewer',
            },
            props: {
                default(route) {
                    return {
                        exampleId: route.params.id,
                    };
                },
            },
        },
    },

    // Plugin-Eintraege OHNE "parent" werden verworfen (erste Ebene ist Plugins nicht erlaubt)
    navigation: [{
        id: 'swag-example',
        label: 'swag-example.general.mainMenuItemGeneral',
        path: 'swag.example.index',
        parent: 'sw-catalogue',
        color: '#ff3d58',
        icon: 'regular-shopping-bag',
        position: 100,
        privilege: 'swag_example.viewer',
    }],

    // Optional: zusaetzlich unter Einstellungen (Gruppe "plugins")
    settingsItem: {
        group: 'plugins',
        to: 'swag.example.index',
        icon: 'regular-rocket',
        label: 'swag-example.general.mainMenuItemGeneral',
        privilege: 'swag_example.viewer',
    },
});
