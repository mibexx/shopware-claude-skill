const path = require('path');

// Path to the administration source incl. node_modules (see README: "npm ci" must have run there).
// Default: Composer project, plugin under custom/plugins/SwagExample.
const adminPath = process.env.ADMIN_PATH
    || path.resolve(__dirname, '../../../../../../../vendor/shopware/administration/Resources/app/administration');

// The preset reads ADMIN_PATH itself (moduleNameMapper for "src/...") and globals.adminPath (setup).
process.env.ADMIN_PATH = adminPath;
process.env.TZ = process.env.TZ || 'UTC';

module.exports = {
    preset: '@shopware-ag/jest-preset-sw6-admin',
    rootDir: __dirname,
    globals: {
        adminPath,
    },
    testEnvironment: 'jsdom',
    collectCoverage: false,
    moduleNameMapper: {
        // same Vue instance as the administration (the preset setup uses the same file)
        '^vue$': path.join(adminPath, 'node_modules/vue/dist/vue.cjs.js'),
        '\\.(css|less|scss)$': '@shopware-ag/jest-preset-sw6-admin/@tool/__mocks__/styleMock.js',
        '^src(.*)$': `${adminPath}/src$1`,
        '^@shopware-ag/admin-extension-sdk/es/(.*)': `${adminPath}/node_modules/@shopware-ag/admin-extension-sdk/umd/$1`,
        '^@shopware-ag/meteor-admin-sdk/es/(.*)': `${adminPath}/node_modules/@shopware-ag/meteor-admin-sdk/umd/$1`,
        '^@shopware-ag/meteor-component-library$': `${adminPath}/node_modules/@shopware-ag/meteor-component-library/dist/common/index.js`,
        '^@shopware-ag/meteor-component-library/dist/esm/(.*)$': `${adminPath}/node_modules/@shopware-ag/meteor-component-library/dist/common/$1`,
        '^@vue/test-utils$': `${adminPath}/node_modules/@vue/test-utils`,
        '^lodash-es/(.*)$': 'lodash/$1',
        '^lodash-es$': 'lodash',
    },
    transformIgnorePatterns: [
        '/node_modules/(?!(@shopware-ag/meteor-component-library|@shopware-ag/meteor-icon-kit|uuidv7)/)',
    ],
    // Plugin tests only; core specs under adminPath are not searched
    testMatch: ['<rootDir>/src/**/*.spec.js', '<rootDir>/src/**/*.spec.ts'],
};
