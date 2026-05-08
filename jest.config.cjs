module.exports = {
  testEnvironment: 'node',
  testTimeout: 10000,
  verbose: true,
  moduleNameMapper: {
    '^marked$': '<rootDir>/__mocks__/marked.cjs'
  }
};