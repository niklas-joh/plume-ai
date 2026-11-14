import { useEffect, useState } from '@wordpress/element';

/**
 * Media query matching WordPress admin's own phone/tablet breakpoint.
 *
 * Below 783px the admin menu collapses and the admin bar grows; Plume's
 * screens switch to their single-column layout at the same point.
 *
 * @type {string}
 */
export const PHONE_LAYOUT_QUERY = '(max-width: 782px)';

/**
 * Whether a CSS media query currently matches, updated as the window resizes.
 *
 * Returns false where matchMedia is unavailable (server rendering, older test
 * environments), which means "use the desktop layout".
 *
 * @param {string} query CSS media query, e.g. PHONE_LAYOUT_QUERY.
 * @return {boolean} True while the query matches.
 *
 * @example
 * const isPhone = useMediaQuery( PHONE_LAYOUT_QUERY );
 */
export function useMediaQuery( query ) {
	const getMatch = () =>
		typeof window !== 'undefined' &&
		typeof window.matchMedia === 'function' &&
		window.matchMedia( query ).matches;

	const [ matches, setMatches ] = useState( getMatch );

	useEffect( () => {
		if ( typeof window.matchMedia !== 'function' ) {
			return;
		}
		const list = window.matchMedia( query );
		const onChange = () => setMatches( list.matches );
		onChange();
		list.addEventListener( 'change', onChange );
		return () => list.removeEventListener( 'change', onChange );
	}, [ query ] );

	return matches;
}
