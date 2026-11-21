import { useState } from '@wordpress/element';
import { ToggleControl, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import UsageSharingDetails from '../../shared/UsageSharingDetails';

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
 * Post-type changes, the write-tools toggle and the usage-sharing toggle are
 * persisted immediately on change.
 *
 * Usage sharing can only be turned on where the server reports it available,
 * but a site that already shares can always turn it off.
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

	const [ telemetryConsent, setTelemetryConsent ] = useState(
		settings?.telemetry_consent ?? 'unset'
	);
	const telemetryAvailable = !! settings?.telemetry_available;

	function handleTelemetryChange( on ) {
		const value = on ? 'yes' : 'no';
		setTelemetryConsent( value );
		saveSettings( { telemetry_consent: value } );
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
						{ __( 'Usage sharing', 'plume' ) }
					</h3>
					<p className="plume-settings-section-desc">
						{ __(
							'Help improve Plume by sharing anonymous usage statistics. Off unless you turn it on.',
							'plume'
						) }
					</p>
				</div>

				<ToggleControl
					label={ __( 'Share anonymous usage statistics', 'plume' ) }
					help={
						! telemetryAvailable && telemetryConsent !== 'yes'
							? __(
									'Usage sharing is turned off on this site.',
									'plume'
								)
							: __(
									'Turning this off stops sending and deletes this site’s random install ID.',
									'plume'
								)
					}
					checked={ telemetryConsent === 'yes' }
					onChange={ handleTelemetryChange }
					disabled={
						! telemetryAvailable && telemetryConsent !== 'yes'
					}
					__nextHasNoMarginBottom
				/>
				<UsageSharingDetails />
			</section>
		</div>
	);
}
