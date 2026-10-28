// Admin privileges "swag_example.viewer|editor|creator|deleter" -> backend privileges <entity>:<operation>.
// Must be loaded via "import './acl'" from module/swag-example/index.js.
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'catalogues', // or null (group "Other"); otherwise catalogues|content|customers|marketing|orders|settings
    key: 'swag_example',
    roles: {
        viewer: {
            privileges: [
                'swag_example:read',
                // helper entities that the pages load
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
