import { Lock } from 'lucide-react';
import PostListTable from '../shared/PostListTable';
import SeoBadge, { getSeoStatus } from './SeoBadge';
import SeoWorkArea from './SeoWorkArea';

const { isPro } = window.wpAiMindData ?? {};

const SEO_TABS = [
	{ id: 'all', label: 'All', filter: () => true },
	{
		id: 'missing',
		label: 'Missing',
		filter: ( p ) => getSeoStatus( p ) === 'missing',
	},
	{
		id: 'partial',
		label: 'Partial',
		filter: ( p ) => getSeoStatus( p ) === 'partial',
	},
	{
		id: 'complete',
		label: 'Complete',
		filter: ( p ) => getSeoStatus( p ) === 'complete',
	},
];

const SEO_COLUMNS = [
	{
		label: 'SEO Status',
		width: 130,
		render: ( post ) => <SeoBadge status={ getSeoStatus( post ) } />,
	},
];

/**
 * Root page component for the AI SEO admin screen (Pro only).
 *
 * Shows a Pro-gate placeholder for free-tier users. Pro users see a
 * PostListTable filtered by SEO completion status, with SeoWorkArea
 * as the expanded row work area.
 *
 * @return {ReactElement}
 */
export default function SeoApp() {
	if ( ! isPro ) {
		return (
			<div className="wpaim-pro-gate">
				<Lock size={ 32 } />
				<h2>AI SEO requires WP AI Mind Pro</h2>
				<p>
					Automatically generate meta titles, OG descriptions,
					excerpts, and image alt text for every post — in one click.
				</p>
				<a
					href="https://wpaimind.com/pricing"
					className="button button-primary button-large"
				>
					Upgrade to Pro →
				</a>
			</div>
		);
	}

	return (
		<div className="wpaim-page">
			<div className="wpaim-page-header">
				<h1>
					SEO <span className="wpaim-pro-badge">PRO</span>
				</h1>
				<p>
					Generate and apply AI-written SEO metadata for your posts
					and pages.
				</p>
			</div>
			<PostListTable
				tabs={ SEO_TABS }
				WorkArea={ SeoWorkArea }
				columns={ SEO_COLUMNS }
			/>
		</div>
	);
}
