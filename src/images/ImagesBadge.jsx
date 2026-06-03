/**
 * Status badge showing whether a post has a featured image.
 *
 * Reads the thumbnail URL from the embedded `wp:featuredmedia` data,
 * so the parent must request `_embed` in the REST query.
 *
 * @param {Object} props
 * @param {Object} props.post  WordPress post object with `featured_media` and `_embedded` set.
 * @return {ReactElement}
 */
export default function ImagesBadge( { post } ) {
	const media = post._embedded?.[ 'wp:featuredmedia' ]?.[ 0 ];
	const thumbUrl =
		media?.media_details?.sizes?.thumbnail?.source_url ?? media?.source_url;

	if ( post.featured_media && thumbUrl ) {
		return (
			<span className="stilus-image-badge-cell">
				<img src={ thumbUrl } alt="" className="stilus-list-thumb" />
				<span className="stilus-badge stilus-badge--has">Has image</span>
			</span>
		);
	}

	return <span className="stilus-badge stilus-badge--none">No image</span>;
}
