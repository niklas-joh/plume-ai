import { __ } from '@wordpress/i18n';
import Badge from './Badge';

const STATUS_LABELS = {
	publish: __( 'Published', 'plume' ),
	draft: __( 'Draft', 'plume' ),
	pending: __( 'Pending', 'plume' ),
	future: __( 'Scheduled', 'plume' ),
	private: __( 'Private', 'plume' ),
};

// Colours are intentionally reused from the SEO-completion badge vocabulary
// (complete/partial/muted) to keep a single palette across the list table; the
// mapping is semantic-per-status here, not a claim that a post is SEO-complete.
const STATUS_VARIANTS = {
	publish: 'complete',
	pending: 'partial',
	future: 'partial',
	draft: 'muted',
	private: 'muted',
};

/**
 * Colour-coded badge showing a post's publish status.
 *
 * @param {Object} props
 * @param {string} props.status  WordPress post status (`publish`, `draft`, `pending`, `future`, `private`, or any other core/custom status).
 * @return {ReactElement}
 *
 * @example
 * <PostStatusBadge status={ post.status } />
 */
export default function PostStatusBadge( { status } ) {
	if ( ! status ) {
		return null;
	}
	const variant = STATUS_VARIANTS[ status ] ?? 'muted';
	return (
		<Badge variant={ variant }>{ STATUS_LABELS[ status ] ?? status }</Badge>
	);
}
