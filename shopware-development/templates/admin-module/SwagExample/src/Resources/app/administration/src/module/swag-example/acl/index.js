// Admin-Privilegien "swag_example.viewer|editor|creator|deleter" -> Backend-Privilegien <entity>:<operation>.
// Muss per "import './acl'" aus module/swag-example/index.js geladen werden.
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'catalogues', // oder null (Gruppe "Other"); sonst catalogues|content|customers|marketing|orders|settings
    key: 'swag_example',
    roles: {
        viewer: {
            privileges: [
                'swag_example:read',
                // Hilfs-Entitaeten, die die Seiten laden
                'user_config:read',
                'user_config:create',
                'user_config:update',
            ],
            dependencies: [],
        },
        editor: {
            privileges: ['swag_example:update'],
            dependencies: ['swag_example.viewer'],
        },
        creator: {
            privileges: ['swag_example:create'],
            dependencies: ['swag_example.viewer', 'swag_example.editor'],
        },
        deleter: {
            privileges: ['swag_example:delete'],
            dependencies: ['swag_example.viewer'],
        },
    },
});
