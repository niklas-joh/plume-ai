import { useState } from '@wordpress/element';
import { SelectControl, TextControl, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const API_KEY_PROVIDERS = [
	{ id: 'claude', label: 'Claude (Anthropic)' },
	{ id: 'openai', label: 'OpenAI' },
	{ id: 'gemini', label: 'Google Gemini' },
];

const PROVIDER_OPTIONS = [
	{ value: 'claude', label: 'Claude' },
	{ value: 'openai', label: 'OpenAI' },
	{ value: 'gemini', label: 'Gemini' },
	{ value: 'ollama', label: 'Ollama (local)' },
];

const IMAGE_PROVIDER_OPTIONS = [
	{ value: 'openai', label: 'OpenAI (DALL·E)' },
	{ value: 'gemini', label: 'Google Gemini' },
];

export default function ProvidersTab( { settings, saveSettings, isSaving } ) {
	const features = window.wpAiMindData?.features ?? {};
	const upgradeUrl =
		window.wpAiMindData?.upgradeUrl ?? 'admin.php?page=wp-ai-mind-upgrade';
	const apiKeys = settings?.api_keys ?? {};
	const [ dirty, setDirty ] = useState( {} ); // { [provider]: string }

	function handleKeyChange( provider, value ) {
		setDirty( ( prev ) => ( { ...prev, [ provider ]: value } ) );
	}

	function handleSaveKey( provider ) {
		const value = dirty[ provider ] ?? '';
		saveSettings( { api_keys: { ...apiKeys, [ provider ]: value } } );
		setDirty( ( prev ) => {
			const next = { ...prev };
			delete next[ provider ];
			return next;
		} );
	}

	function handleUrlChange( value ) {
		setDirty( ( prev ) => ( { ...prev, ollama_url: value } ) );
	}

	function handleSaveUrl() {
		const value = dirty.ollama_url ?? '';
		saveSettings( { api_keys: { ...apiKeys, ollama_url: value } } );
		setDirty( ( prev ) => {
			const next = { ...prev };
			delete next.ollama_url;
			return next;
		} );
	}

	return (
		<div className="wpaim-providers-tab">
			{ /* Default & image provider selects */ }
			<section className="wpaim-settings-section">
				<h3 className="wpaim-settings-section-title">
					Default Providers
				</h3>

				{ ! features.model_selection && (
					<p className="wpaim-upgrade-notice">
						{ __(
							'Model selection is available on the Pro plan.',
							'wp-ai-mind'
						) }{ ' ' }
						<a href={ upgradeUrl }>
							{ __( 'Upgrade →', 'wp-ai-mind' ) }
						</a>
					</p>
				) }

				<fieldset disabled={ ! features.model_selection }>
					<SelectControl
						label={ __( 'Default AI Provider', 'wp-ai-mind' ) }
						options={ PROVIDER_OPTIONS }
						value={ settings?.default_provider ?? '' }
						onChange={ ( val ) =>
							saveSettings( { default_provider: val } )
						}
						__nextHasNoMarginBottom
					/>

					<SelectControl
						label={ __( 'Image Provider', 'wp-ai-mind' ) }
						options={ IMAGE_PROVIDER_OPTIONS }
						value={ settings?.image_provider ?? '' }
						onChange={ ( val ) =>
							saveSettings( { image_provider: val } )
						}
						__nextHasNoMarginBottom
					/>
				</fieldset>
			</section>

			{ /* API key inputs */ }
			<section className="wpaim-settings-section">
				<h3 className="wpaim-settings-section-title">API Keys</h3>

				{ ! features.own_api_key && (
					<p className="wpaim-upgrade-notice">
						{ __(
							'API key management is available on the Pro BYOK plan.',
							'wp-ai-mind'
						) }{ ' ' }
						<a href={ upgradeUrl }>
							{ __( 'Upgrade →', 'wp-ai-mind' ) }
						</a>
					</p>
				) }

				{ API_KEY_PROVIDERS.map( ( { id, label } ) => (
					<div
						key={ id }
						className="wpaim-field-row wpaim-field-row--key"
					>
						<div className="wpaim-field-input-group">
							<TextControl
								label={ label }
								type="password"
								value={ dirty[ id ] ?? '' }
								placeholder={
									apiKeys[ id ]
										? '••••••••••••'
										: 'Enter API key…'
								}
								onChange={ ( val ) =>
									handleKeyChange( id, val )
								}
								autoComplete="new-password"
								__nextHasNoMarginBottom
								disabled={ ! features.own_api_key }
							/>
							<Button
								variant="primary"
								disabled={
									isSaving ||
									dirty[ id ] === undefined ||
									! features.own_api_key
								}
								onClick={ () => handleSaveKey( id ) }
							>
								{ isSaving ? 'Saving…' : 'Save' }
							</Button>
						</div>
					</div>
				) ) }
			</section>

			{ /* Ollama URL */ }
			<section className="wpaim-settings-section">
				<h3 className="wpaim-settings-section-title">
					Ollama (Self-hosted)
				</h3>

				<div className="wpaim-field-row wpaim-field-row--key">
					<div className="wpaim-field-input-group">
						<TextControl
							label="Ollama URL"
							type="url"
							value={ dirty.ollama_url ?? '' }
							placeholder={
								apiKeys.ollama_url ?? 'http://localhost:11434'
							}
							onChange={ handleUrlChange }
							__nextHasNoMarginBottom
						/>
						<Button
							variant="primary"
							disabled={
								isSaving || dirty.ollama_url === undefined
							}
							onClick={ handleSaveUrl }
						>
							{ isSaving ? 'Saving…' : 'Save' }
						</Button>
					</div>
				</div>
			</section>
		</div>
	);
}
