// Transparent stub for @wordpress/api-fetch.
// @wordpress/api-fetch is a webpack external — it has no standalone npm
// package. This stub allows Jest to resolve the import; individual test files
// replace it with jest.mock() and control the mock implementation there.
const apiFetch = jest.fn();
module.exports = apiFetch;
