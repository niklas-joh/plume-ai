// Jest setup file — runs after the test framework is installed.
// Sets the React 18 act() environment flag so that act() calls within
// jsdom do not emit "not configured to support act()" warnings, which
// @wordpress/jest-console would treat as test failures.
globalThis.IS_REACT_ACT_ENVIRONMENT = true;
