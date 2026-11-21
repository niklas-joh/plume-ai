import { __ } from '@wordpress/i18n';
import PageHeader from '../shared/PageHeader';
import Screen from '../shared/Screen';
import PostListTable from '../shared/PostListTable';
import PostStatusBadge from '../shared/PostStatusBadge';
import SeoBadge, { getSeoStatus } from './SeoBadge';
import SeoWorkArea from './SeoWorkArea';

const SEO_TABS = [
	{ id: 'all', label: __( 'All', 'plume' ), filter: () => true },
	{
		id: 'missing',
		label: __( 'Missing', 'plume' ),
		filter: ( p ) => getSeoStatus( p ) === 'missing',
	},
	{
		id: 'partial',
		label: __( 'Partial', 'plume' ),
		filter: ( p ) => getSeoStatus( p ) === 'partial',
	},
	{
		id: 'complete',
		label: __( 'Complete', 'plume' ),
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
		label: __( 'SEO Status', 'plume' ),
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
		<Screen
			header={
				<PageHeader
					title={ __( 'SEO', 'plume' ) }
					description={ __(
						'Generate meta titles, descriptions, excerpts and image alt text for your posts.',
						'plume'
					) }
				/>
			}
		>
			<PostListTable
				tabs={ SEO_TABS }
				WorkArea={ SeoWorkArea }
				columns={ SEO_COLUMNS }
			/>
		</Screen>
	);
}
