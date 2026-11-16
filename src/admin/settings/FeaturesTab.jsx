import { useState } from '@wordpress/element';
import { ToggleControl, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const MODULES = [
	{
		slug: 'chat',
		label: __( 'Chat', 'plume' ),
		description: __(
			'AI chat assistant panel in the WordPress admin.',
			'plume'
		),
	},
	{
		slug: 'text_rewrite',
		label: __( 'Text Rewrite', 'plume' ),
		description: __(
			'Rewrite and improve post content using AI.',
			'plume'
		),
	},
	{
		slug: 'summaries',
		label: __( 'Summaries', 'plume' ),
		description: __(
			'Automatically generate post summaries and excerpts.',
			'plume'
		),
	},
	{
		slug: 'seo',
		label: __( 'SEO', 'plume' ),
		description: __(
			'AI-assisted meta titles, descriptions, and keyword suggestions.',
			'plume'
		),
	},
	{
		slug: 'images',
		label: __( 'Images', 'plume' ),
		description: __(
			'Generate and insert AI images directly into posts.',
			'plume'
		),
	},
	{
		slug: 'generator',
		label: __( 'Generator', 'plume' ),
		description: __(
			'Full AI-driven post and page generation workflow.',
			'plume'
		),
	},
	{
		slug: 'usage',
		label: __( 'Usage', 'plume' ),
		description: __(
			'Track token consumption and API usage across providers.',
			'plume'
		),
	},
];

/**
 * Settings tab for toggling AI modules and configuring post-type access.
 *
 * Every module is available on every tier — credit exhaustion is enforced
 * by the Worker, not a PHP/JS-side feature gate, so no card is ever locked.
 * Post-type changes, the write-tools toggle and the usage-statistics toggle
 * are persisted immediately on change.
 *
 * @param {Object}   props
 * @param {Object}   props.settings      Full settings object from the REST API.
 * @param {Function} props.saveSettings  Persists a partial settings patch via POST.
 * @return {ReactElement}
 */
export default function FeaturesTab( { settings, saveSettings } ) {
	const enabledModules = settings?.enabled_modules ?? [];

	const [ allowedPostTypes, setAllowedPostTypes ] = useState(
		settings?.allowed_post_types ?? [ 'post', 'page' ]
	);
	const [ availablePostTypes ] = useState(
		settings?.available_post_types ?? []
	);
	const [ enableWriteTools, setEnableWriteTools ] = useState(
		settings?.enable_write_tools ?? false
	);
	const [ shareUsageStats, setShareUsageStats ] = useState(
		settings?.share_usage_stats ?? true
	);

	function handleToggle( slug ) {
		const isEnabled = enabledModules.includes( slug );
		const updatedArray = isEnabled
			? enabledModules.filter( ( s ) => s !== slug )
			: [ ...enabledModules, slug ];

		saveSettings( { enabled_modules: updatedArray } );
	}

	function handlePostTypeChange( slug, checked ) {
		const updated = checked
			? [ ...allowedPostTypes, slug ]
			: allowedPostTypes.filter( ( s ) => s !== slug );
		setAllowedPostTypes( updated );
		saveSettings( { allowed_post_types: updated } );
	}

	function handleWriteToolsChange( val ) {
		setEnableWriteTools( val );
		saveSettings( { enable_write_tools: val } );
	}

	function handleShareUsageStatsChange( val ) {
		setShareUsageStats( val );
		saveSettings( { share_usage_stats: val } );
	}

	return (
		<div className="plume-features-tab">
			<section className="plume-settings-section">
				<div className="plume-settings-section-header">
					<h3 className="plume-settings-section-title">
						{ __( 'Enabled Modules', 'plume' ) }
					</h3>
					<p className="plume-settings-section-desc">
						{ __(
							'Enable or disable individual AI modules.',
							'plume'
						) }
					</p>
				</div>

				<div className="plume-features-grid">
					{ MODULES.map( ( { slug, label, description } ) => {
						const isEnabled = enabledModules.includes( slug );

						return (
							<div
								key={ slug }
								className={ `plume-feature-card${
									isEnabled
										? ' plume-feature-card--enabled'
										: ''
								}` }
							>
								<div className="plume-feature-card-header">
									<div className="plume-feature-card-meta">
										<span className="plume-feature-card-label">
											{ label }
										</span>
									</div>

									<ToggleControl
										label={ label }
										checked={ isEnabled }
										onChange={ () => handleToggle( slug ) }
										hideLabelFromVision
										__nextHasNoMarginBottom
									/>
								</div>

								<p className="plume-feature-card-desc">
									{ description }
								</p>
							</div>
						);
					} ) }
				</div>
			</section>

			<section className="plume-settings-section">
				<div className="plume-settings-section-header">
					<h3 className="plume-settings-section-title">
						{ __( 'Post Type Access', 'plume' ) }
					</h3>
					<p className="plume-settings-section-desc">
						{ __(
							'Select which post types the AI assistant can read and write.',
							'plume'
						) }
					</p>
				</div>

				<div className="plume-post-type-list">
					{ availablePostTypes.map( ( { slug, label } ) => (
						<CheckboxControl
							key={ slug }
							label={
								<>
									{ label } <code>{ slug }</code>
								</>
							}
							checked={ allowedPostTypes.includes( slug ) }
							onChange={ ( checked ) =>
								handlePostTypeChange( slug, checked )
							}
							__nextHasNoMarginBottom
						/>
					) ) }
				</div>

				<div style={ { marginTop: '1rem' } }>
					<ToggleControl
						label={ __(
							'Enable write tools — allow AI to create and update posts',
							'plume'
						) }
						help={ __(
							'When enabled, the AI can draft and publish content directly.',
							'plume'
						) }
						checked={ enableWriteTools }
						onChange={ handleWriteToolsChange }
						__nextHasNoMarginBottom
					/>
				</div>
			</section>

			<section className="plume-settings-section">
				<div className="plume-settings-section-header">
					<h3 className="plume-settings-section-title">
						{ __( 'Usage Statistics', 'plume' ) }
					</h3>
				</div>

				<ToggleControl
					label={ __(
						'Share usage statistics with the Plume managed service',
						'plume'
					) }
					help={ __(
						'Applies only when you use the Plume managed service (no API key of your own). For each request and registration, the service records your site domain, plan, feature, AI provider and model, token counts, credits used, response time and whether it succeeded. Prompts and responses are never included. Turn this off to stop recording these statistics from now on; credit counting continues, so your plan limits still apply.',
						'plume'
					) }
					checked={ shareUsageStats }
					onChange={ handleShareUsageStatsChange }
					__nextHasNoMarginBottom
				/>
			</section>
		</div>
	);
}
