/**
 * Extracts the user-facing text from an apiFetch rejection.
 *
 * apiFetch rejects with the parsed JSON body of the failed response. Core REST
 * errors carry the text in `message`, but several Plume routes (Generator, SEO,
 * Images) send it in `error` — reading `message` alone silently replaced every
 * real server message from those routes with a generic fallback.
 *
 * @param {{message?: string, error?: string}|Error|null|undefined} error    apiFetch rejection value.
 * @param {string}                                                  fallback Text returned when the rejection carries no usable message.
 * @return {string} The server's message, or `fallback`.
 *
 * @example
 * setError( getErrorMessage( e, __( 'Generation failed.', 'plume' ) ) );
 */
export function getErrorMessage( error, fallback ) {
	const candidates = [ error?.message, error?.error ];
	const text = candidates.find(
		( value ) => typeof value === 'string' && value.trim() !== ''
	);
	return text ?? fallback;
}
