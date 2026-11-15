import { __ } from '@wordpress/i18n';

const TILES = [
	{
		verb: __( 'Write a new post', 'plume' ),
		desc: __( 'Describe what you want — AI drafts it for you.', 'plume' ),
		urlKey: 'generator',
		primary: true,
	},
	{
		verb: __( 'Edit with AI', 'plume' ),
		desc: __(
			'Open any post with the AI sidebar to rewrite or improve.',
			'plume'
		),
		urlKey: 'posts',
		primary: false,
	},
	{
		verb: __( 'Generate an image', 'plume' ),
		desc: __(
			'Create a featured image or illustration from a prompt.',
			'plume'
		),
		urlKey: 'images',
		primary: false,
	},
	{
		verb: __( 'Chat', 'plume' ),
		desc: __(
			'Brainstorm, research, or ask anything about your content.',
			'plume'
		),
		urlKey: 'chat',
		primary: false,
	},
];

/**
 * Grid of quick-start action tiles on the dashboard.
 *
 * @param {Object} props
 * @param {Object} props.urls  URL map keyed by tile `urlKey` values (e.g. `generator`, `chat`).
 * @return {ReactElement}
 */
export default function StartTiles( { urls } ) {
	return (
		<div>
			<div className="plume-dash-section-head">
				<span className="plume-dash-section-title">
					{ __( 'Start', 'plume' ) }
				</span>
			</div>
			<div className="plume-dash-tiles">
				{ TILES.map( ( tile ) => (
					<a
						key={ tile.urlKey }
						href={ urls[ tile.urlKey ] }
						className={ `plume-dash-tile${
							tile.primary ? ' plume-dash-tile--primary' : ''
						}` }
					>
						<div className="plume-dash-tile__verb">
							{ tile.verb }
						</div>
						<div className="plume-dash-tile__desc">
							{ tile.desc }
						</div>
						<span className="plume-dash-tile__arrow">&#x2197;</span>
					</a>
				) ) }
			</div>
		</div>
	);
}
