import { useState } from '@wordpress/element';
import { ToggleControl, CheckboxControl } from '@wordpress/components';
import { Lock } from 'lucide-react';

const MODULES = [
	{
		slug: 'chat',
		label: 'Chat',
		description: 'AI chat assistant panel in the WordPress admin.',
		isPro: false,
	},
	{
		slug: 'text_rewrite',
		label: 'Text Rewrite',
		description: 'Rewrite and improve post content using AI.',
		isPro: false,
	},
	{
		slug: 'summaries',
		label: 'Summaries',
		description: 'Automatically generate post summaries and excerpts.',
		isPro: false,
	},
	{
		slug: 'seo',
		label: 'SEO',
		description:
			'AI-assisted meta titles, descriptions, and keyword suggestions.',
		isPro: true,
	},
	{
		slug: 'images',
		label: 'Images',
		description: 'Generate and insert AI images directly into posts.',
		isPro: true,
	},
	{
		slug: 'generator',
		label: 'Generator',
		description: 'Full AI-driven post and page generation workflow.',
		isPro: true,
	},
	{
		slug: 'frontend_widget',
		label: 'Frontend Widget',
		description: 'Embed an AI chat widget on the public-facing site.',
		isPro: true,
	},
	{
		slug: 'usage',
		label: 'Usage',
		description: 'Track token consumption and API usage across providers.',
		isPro: false,
	},
];

export default function FeaturesTab( { settings, saveSettings } ) {
	const enabledModules = settings?.enabled_modules ?? [];
	const isPro = settings?.is_pro ?? false;

	const [ allowedPostTypes, setAllowedPostTypes ] = useState(
		settings?.allowed_post_types ?? [ 'post', 'page' ]
	);
	const [ availablePostTypes ] = useState(
		settings?.available_post_types ?? []
	);
	const [ enableWriteTools, setEnableWriteTools ] = useState(
		settings?.enable_write_tools ?? false
	);

	function handleToggle( slug, isProModule ) {
		if ( isProModule && ! isPro ) {
			return;
		} // locked

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

	return (
		<div className="wpaim-features-tab">
			<section className="wpaim-settings-section">
				<div className="wpaim-settings-section-header">
					<h3 className="wpaim-settings-section-title">
						Enabled Modules
					</h3>
					<p className="wpaim-settings-section-desc">
						Enable or disable individual AI modules. Pro modules
						require an active WP AI Mind Pro licence.
					</p>
				</div>

				<div className="wpaim-features-grid">
					{ MODULES.map(
						( {
							slug,
							label,
							description,
							isPro: isProModule,
						} ) => {
							const isEnabled = enabledModules.includes( slug );
							const isLocked = isProModule && ! isPro;

							return (
								<div
									key={ slug }
									className={ `wpaim-feature-card${
										isLocked
											? ' wpaim-feature-card--locked'
											: ''
									}${
										isEnabled
											? ' wpaim-feature-card--enabled'
											: ''
									}` }
								>
									<div className="wpaim-feature-card-header">
										<div className="wpaim-feature-card-meta">
											<span className="wpaim-feature-card-label">
												{ label }
											</span>
											{ isProModule && (
												<span className="wpaim-pro-badge">
													{ isLocked && (
														<Lock size={ 10 } />
													) }
													Pro
												</span>
											) }
										</div>

										<ToggleControl
											label={ label }
											checked={ isEnabled }
											onChange={ () =>
												handleToggle(
													slug,
													isProModule
												)
											}
											disabled={ isLocked }
											hideLabelFromVision
											__nextHasNoMarginBottom
										/>
									</div>

									<p className="wpaim-feature-card-desc">
										{ description }
									</p>

									{ isLocked && (
										<p className="wpaim-feature-card-locked-msg">
											<Lock size={ 11 } />
											Requires WP AI Mind Pro
										</p>
									) }
								</div>
							);
						}
					) }
				</div>
			</section>

			<section className="wpaim-settings-section">
				<div className="wpaim-settings-section-header">
					<h3 className="wpaim-settings-section-title">
						Post Type Access
					</h3>
					<p className="wpaim-settings-section-desc">
						Select which post types the AI assistant can read and
						write.
					</p>
				</div>

				<div className="wpaim-post-type-list">
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
						label="Enable write tools — allow AI to create and update posts"
						help="When enabled, the AI can draft and publish content directly."
						checked={ enableWriteTools }
						onChange={ handleWriteToolsChange }
						__nextHasNoMarginBottom
					/>
				</div>
			</section>
		</div>
	);
}
