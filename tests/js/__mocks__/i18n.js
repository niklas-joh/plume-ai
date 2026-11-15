// Stub for @wordpress/i18n — not installed as a standalone npm package.
// Returns the English source strings, but substitutes sprintf placeholders and picks
// the plural form by count, so tests can assert the text a user actually sees.

/**
 * Minimal sprintf: supports %s, %d, positional %1$s / %2$d, and %%.
 *
 * @param {string} format Format string.
 * @param {...*}   args   Values to substitute.
 * @return {string} The formatted string.
 */
const sprintf = ( format, ...args ) => {
	let next = 0;
	return String( format ).replace(
		/%(?:(\d+)\$)?([sd%])/g,
		( match, position, type ) => {
			if ( '%' === type ) {
				return '%';
			}
			const value = position
				? args[ parseInt( position, 10 ) - 1 ]
				: args[ next++ ];
			if ( undefined === value ) {
				return match;
			}
			return 'd' === type
				? String( parseInt( value, 10 ) )
				: String( value );
		}
	);
};

module.exports = {
	__: ( str ) => str,
	_x: ( str ) => str,
	_n: ( singular, plural, count ) => ( 1 === count ? singular : plural ),
	_nx: ( singular, plural, count ) => ( 1 === count ? singular : plural ),
	sprintf,
};
