const STATUS_LABELS = {
	complete: 'Complete',
	partial: 'Partial',
	missing: 'Missing',
};

/**
 * Derive a post's SEO completion status from its SEO metadata.
 *
 * @param {Object} post WordPress post object with `wpaim_seo_status` field.
 * @return {string} One of `'complete'`, `'partial'`, or `'missing'`.
 */
export function getSeoStatus( post ) {
	const s = post.wpaim_seo_status;
	if ( ! s ) {
		return 'missing';
	}
	const values = Object.values( s );
	const filledCount = values.filter( ( v ) => v === 'filled' ).length;
	if ( filledCount === 0 ) {
		return 'missing';
	}
	if ( filledCount === values.length ) {
		return 'complete';
	}
	return 'partial';
}

/**
 * Colour-coded badge indicating a post's SEO completion status.
 *
 * @param {Object} props
 * @param {string} props.status  One of `'complete'`, `'partial'`, or `'missing'`.
 * @return {ReactElement}
 */
export default function SeoBadge( { status } ) {
	return (
		<span className={ `wpaim-badge wpaim-badge--${ status }` }>
			{ STATUS_LABELS[ status ] ?? status }
		</span>
	);
}
