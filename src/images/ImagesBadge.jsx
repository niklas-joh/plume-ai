export default function ImagesBadge( { post } ) {
	const media = post._embedded?.[ 'wp:featuredmedia' ]?.[ 0 ];
	const thumbUrl =
		media?.media_details?.sizes?.thumbnail?.source_url ?? media?.source_url;

	if ( post.featured_media && thumbUrl ) {
		return (
			<span className="wpaim-image-badge-cell">
				<img src={ thumbUrl } alt="" className="wpaim-list-thumb" />
				<span className="wpaim-badge wpaim-badge--has">Has image</span>
			</span>
		);
	}

	return <span className="wpaim-badge wpaim-badge--none">No image</span>;
}
