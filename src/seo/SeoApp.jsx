import { __ } from '@wordpress/i18n';
import PostListTable from '../shared/PostListTable';
import PostStatusBadge from '../shared/PostStatusBadge';
import SeoBadge, { getSeoStatus } from './SeoBadge';
import SeoWorkArea from './SeoWorkArea';

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
		label: __( 'Post Status', 'plume' ),
		width: 100,
		render: ( post ) => <PostStatusBadge status={ post.status } />,
	},
	{
		label: 'SEO Status',
		width: 130,
		render: ( post ) => <SeoBadge status={ getSeoStatus( post ) } />,
	},
];

/**
 * Root page component for the AI SEO admin screen.
 *
 * Available to every tier — credit exhaustion is surfaced inline by
 * SeoWorkArea via OutOfCreditsNotice when a generation request fails, not
 * by gating the whole screen up front.
 *
 * @return {ReactElement}
 */
export default function SeoApp() {
	return (
		<div className="plume-page">
			<div className="plume-page-header">
				<h1>SEO</h1>
				<p>
					Automatically generate meta titles, OG descriptions,
					excerpts, and image alt text for every post — in one click.
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
