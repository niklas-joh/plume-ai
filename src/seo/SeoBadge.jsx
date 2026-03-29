const STATUS_LABELS = {
	complete: 'Complete',
	partial: 'Partial',
	missing: 'Missing',
};

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

export default function SeoBadge( { status } ) {
	return (
		<span className={ `wpaim-badge wpaim-badge--${ status }` }>
			{ STATUS_LABELS[ status ] ?? status }
		</span>
	);
}
