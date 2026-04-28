const RESOURCES = [
	{
		title: 'Getting started guide',
		desc: 'Five minutes to your first AI-generated post.',
		urlKey: 'gettingStarted',
	},
	{
		title: 'Prompt writing tips',
		desc: 'How to write prompts that consistently produce better output.',
		urlKey: 'promptTips',
	},
	{
		title: 'API key setup',
		desc: 'Connect OpenAI, Claude, or Gemini to remove usage limits.',
		urlKey: 'apiKeySetup',
	},
	{
		title: 'Changelog',
		desc: null, // version string injected below
		urlKey: 'changelog',
	},
];

/**
 * List of external documentation and resource links shown on the dashboard.
 *
 * The changelog entry uses `version` to build a dynamic description when
 * the static `desc` field is null.
 *
 * @param {Object} props
 * @param {Object} props.resourceUrls  URL map keyed by resource `urlKey` values.
 * @param {string} props.version       Current plugin version string (e.g. `'1.2.0'`).
 * @return {ReactElement}
 */
export default function ResourceList( { resourceUrls, version } ) {
	return (
		<div>
			<div className="wpaim-dash-section-head">
				<span className="wpaim-dash-section-title">Resources</span>
			</div>
			<div className="wpaim-dash-resources">
				{ RESOURCES.map( ( item ) => (
					<a
						key={ item.urlKey }
						href={ resourceUrls[ item.urlKey ] }
						className="wpaim-dash-resource"
						target="_blank"
						rel="nofollow noreferrer"
					>
						<div className="wpaim-dash-resource__body">
							<div className="wpaim-dash-resource__title">
								{ item.title }
							</div>
							<div className="wpaim-dash-resource__desc">
								{ item.desc ?? `What's new in v${ version }.` }
							</div>
						</div>
						<span className="wpaim-dash-resource__arrow">
							&#x2197;
						</span>
					</a>
				) ) }
			</div>
		</div>
	);
}
