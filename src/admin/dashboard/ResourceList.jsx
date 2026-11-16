import { __, sprintf } from '@wordpress/i18n';

const RESOURCES = [
	{
		title: __( 'Getting started guide', 'plume' ),
		desc: __( 'Five minutes to your first AI-generated post.', 'plume' ),
		urlKey: 'gettingStarted',
	},
	{
		title: __( 'Prompt writing tips', 'plume' ),
		desc: __(
			'How to write prompts that consistently produce better output.',
			'plume'
		),
		urlKey: 'promptTips',
	},
	{
		title: __( 'API key setup', 'plume' ),
		desc: __(
			'Connect OpenAI, Claude, or Gemini to remove usage limits.',
			'plume'
		),
		urlKey: 'apiKeySetup',
	},
	{
		title: __( 'Changelog', 'plume' ),
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
			<div className="plume-dash-section-head">
				<span className="plume-dash-section-title">
					{ __( 'Resources', 'plume' ) }
				</span>
			</div>
			<div className="plume-dash-resources">
				{ RESOURCES.map( ( item ) => (
					<a
						key={ item.urlKey }
						href={ resourceUrls[ item.urlKey ] }
						className="plume-dash-resource"
						target="_blank"
						rel="nofollow noreferrer"
					>
						<div className="plume-dash-resource__body">
							<div className="plume-dash-resource__title">
								{ item.title }
							</div>
							<div className="plume-dash-resource__desc">
								{ item.desc ??
									sprintf(
										/* translators: %s: plugin version number, e.g. "1.2.0". */
										__( "What's new in v%s.", 'plume' ),
										version
									) }
							</div>
						</div>
						<span className="plume-dash-resource__arrow">
							&#x2197;
						</span>
					</a>
				) ) }
			</div>
		</div>
	);
}
