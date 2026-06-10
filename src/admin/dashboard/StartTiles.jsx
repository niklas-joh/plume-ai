const TILES = [
	{
		verb: 'Write a new post',
		desc: 'Describe what you want — AI drafts it for you.',
		urlKey: 'generator',
		primary: true,
	},
	{
		verb: 'Edit with AI',
		desc: 'Open any post with the AI sidebar to rewrite or improve.',
		urlKey: 'posts',
		primary: false,
	},
	{
		verb: 'Generate an image',
		desc: 'Create a featured image or illustration from a prompt.',
		urlKey: 'images',
		primary: false,
	},
	{
		verb: 'Chat',
		desc: 'Brainstorm, research, or ask anything about your content.',
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
				<span className="plume-dash-section-title">Start</span>
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
