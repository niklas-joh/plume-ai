/**
 * Safe localStorage wrappers that fall back gracefully when storage access is
 * denied (e.g. browser storage partitioning, private mode, embedded contexts).
 */

/**
 * Read a value from localStorage without throwing.
 *
 * @param {string} key          Storage key.
 * @param {string|null} [fallback]   Value returned when the key is missing or storage is unavailable.
 * @return {string|null} Stored string, or `fallback`.
 */
export function storageGet( key, fallback = null ) {
	try {
		return window.localStorage.getItem( key ) ?? fallback;
	} catch {
		return fallback;
	}
}

/**
 * Write a value to localStorage without throwing.
 *
 * @param {string} key   Storage key.
 * @param {string} value Value to store.
 * @return {void}
 */
export function storageSet( key, value ) {
	try {
		window.localStorage.setItem( key, value );
	} catch {
		// Storage unavailable — silently ignore.
	}
}
