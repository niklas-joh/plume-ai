import { Lock } from 'lucide-react';
import PostListTable from '../shared/PostListTable';
import ImagesBadge from './ImagesBadge';
import ImagesWorkArea from './ImagesWorkArea';

const { isPro, websiteUrl = 'https://wpaimind.com' } = window.plumeData ?? {};

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
 * Root page component for the AI Images admin screen (Pro only).
 *
 * Shows a Pro-gate placeholder for free-tier users. Pro users see a
 * PostListTable filtered by featured-image presence, with ImagesWorkArea
 * as the expanded row work area.
 *
 * @return {ReactElement}
 */
export default function ImagesApp() {
	if ( ! isPro ) {
		return (
			<div className="plume-pro-gate">
				<Lock size={ 32 } />
				<h2>AI image generation requires Plume Pro</h2>
				<p>
					Generate beautiful featured images from a text prompt and
					set them directly on any post or page.
				</p>
				<a
					href={ `${ websiteUrl }/pricing` }
					className="button button-primary button-large"
				>
					Upgrade to Pro →
				</a>
			</div>
		);
	}

	return (
		<div className="plume-page">
			<div className="plume-page-header">
				<h1>
					Images <span className="plume-pro-badge">PRO</span>
				</h1>
				<p>
					Generate featured images for your posts and pages with AI.
				</p>
			</div>
			<PostListTable
				tabs={ IMAGES_TABS }
				WorkArea={ ImagesWorkArea }
				columns={ IMAGES_COLUMNS }
			/>
		</div>
	);
}
