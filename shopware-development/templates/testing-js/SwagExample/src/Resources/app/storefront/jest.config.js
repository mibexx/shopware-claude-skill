const { join } = require('path');

module.exports = {
    testEnvironment: 'jsdom',
    clearMocks: true,
    resetMocks: true,
    restoreMocks: true,
    rootDir: __dirname,
    moduleFileExtensions: ['js', 'ts', 'html'],
    modulePaths: ['<rootDir>/node_modules'],
    moduleNameMapper: {
        '^src/(.*)$': '<rootDir>/../../../../../../../vendor/shopware/storefront/Resources/app/storefront/src/$1',
        '^plugin/(.*)$': '<rootDir>/src/$1',
    },
    testMatch: ['**/test/**/*.test.js'],
    transform: { '^.+\\.(t|j)s$': 'babel-jest' },
    setupFilesAfterEnv: [join(__dirname, 'jest.init.js')],
};
