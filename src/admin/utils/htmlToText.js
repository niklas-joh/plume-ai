/**
 * Extracts the plain-text content of an HTML string.
 *
 * Shared by the diff machinery: `DiffView` uses it to build clean aria-label
 * text, and `computeDiff`'s `normalizeForComparison` uses it for LCS equality
 * keys. Both implemented the same `div.innerHTML -> textContent` parse before.
 *
 * The non-DOM fallback returns an empty string rather than stripping tags with
 * a regex: both callers only ever run in the browser, and `/<[^>]*>/g` is
 * flagged by static analysis as incomplete HTML sanitisation
 * (CodeQL rule: js/incomplete-html-tag-sanitization).
 *
 * @param {string} html  HTML fragment to flatten to text.
 * @return {string}      The fragment's text content (empty when no DOM).
 */
export function htmlToText( html ) {
	if ( typeof document !== 'undefined' ) {
		const div = document.createElement( 'div' );
		div.innerHTML = html;
		return div.textContent;
	}
	return '';
}
