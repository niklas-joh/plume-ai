import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import PostListTable from '../shared/PostListTable';
import ImagesBadge from './ImagesBadge';
import ImagesWorkArea from './ImagesWorkArea';

const IMAGES_TABS = [
	{ id: 'all', label: 'All', filter: () => true },
	{ id: 'no-image', label: 'No image', filter: ( p ) => ! p.featured_media },
	{
		id: 'has-image',
		label: 'Has image',
		filter: ( p ) => !! p.featured_media,
	},
];

const IMAGES_COLUMNS = [
	{
		label: 'Featured Image',
		width: 180,
		render: ( post ) => <ImagesBadge post={ post } />,
	},
];

/**
 * Root page component for the AI Images admin screen.
 *
 * Available to every tier — credit exhaustion is surfaced inline by
 * ImagesWorkArea via OutOfCreditsNotice when a generation request fails,
 * not by gating the whole screen up front.
 *
 * Images are always generated with the site's own OpenAI or Gemini key, never
 * through the managed service. When that key is missing the screen says so up
 * front, because a first-time user would otherwise only find out from a failed
 * request.
 *
 * @return {ReactElement}
 */
export default function ImagesApp() {
	const { imageProvider, settingsUrl = '' } = window.plumeData ?? {};
	// Strict comparison: only warn when the server has said the key is missing.
	const keyMissing = imageProvider?.hasKey === false;

	return (
		<div className="plume-page">
			<div className="plume-page-header">
				<h1>Images</h1>
				<p>
					Generate beautiful featured images from a text prompt and
					set them directly on any post or page.
				</p>
			</div>
			{ keyMissing && (
				<Notice
					status="warning"
					isDismissible={ false }
					className="plume-images-key-notice"
				>
					{ sprintf(
						/* translators: %s: AI provider name, e.g. "OpenAI". */
						__(
							'AI images use your own API key, and no %s key has been added yet. Adding one is free on any plan; you pay the provider directly.',
							'plume'
						),
						imageProvider.label
					) }{ ' ' }
					{ settingsUrl ? (
						<a href={ settingsUrl }>
							{ __( 'Add a key in Settings', 'plume' ) }
						</a>
					) : (
						__(
							'Ask a site administrator to add one in Plume AI → Settings.',
							'plume'
						)
					) }
				</Notice>
			) }
			<PostListTable
				tabs={ IMAGES_TABS }
				WorkArea={ ImagesWorkArea }
				columns={ IMAGES_COLUMNS }
			/>
		</div>
	);
}
