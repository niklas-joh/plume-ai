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

/**
 * Settings tab for configuring AI providers, API keys, and the Ollama endpoint.
 *
 * API keys are stored per-provider in a dirty-state map and only persisted
 * when the user explicitly clicks Save, preventing accidental overwrites.
 * Pro feature gates (model_selection, own_api_key) are read from plumeData.
 *
 * @param {Object}   props
 * @param {Object}   props.settings      Full settings object from the REST API.
 * @param {Function} props.saveSettings  Persists a partial settings patch via POST.
 * @param {boolean}  props.isSaving      True while a save request is in flight.
 * @return {ReactElement}
 */
export default function ProvidersTab( { settings, saveSettings, isSaving } ) {
	const features = window.plumeData?.features ?? {};
	const upgradeUrl =
		window.plumeData?.upgradeUrl ?? 'admin.php?page=plume-upgrade';
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
		<div className="plume-providers-tab">
			{ /* Default & image provider selects */ }
			<section className="plume-settings-section">
				<h3 className="plume-settings-section-title">
					Default Providers
				</h3>

				{ ! features.model_selection && (
					<p className="plume-upgrade-notice">
						{ __(
							'Model selection is available on the Pro plan.',
							'plume'
						) }{ ' ' }
						<a href={ upgradeUrl }>
							{ __( 'Upgrade →', 'plume' ) }
						</a>
					</p>
				) }

				<fieldset disabled={ ! features.model_selection }>
					<SelectControl
						label={ __( 'Default AI Provider', 'plume' ) }
						options={ PROVIDER_OPTIONS }
						value={ settings?.default_provider ?? '' }
						onChange={ ( val ) =>
							saveSettings( { default_provider: val } )
						}
						__nextHasNoMarginBottom
					/>

					<SelectControl
						label={ __( 'Image Provider', 'plume' ) }
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
			<section className="plume-settings-section">
				<h3 className="plume-settings-section-title">API Keys</h3>

				{ ! features.own_api_key && (
					<p className="plume-upgrade-notice">
						{ __(
							'API key management is available on the Pro BYOK plan.',
							'plume'
						) }{ ' ' }
						<a href={ upgradeUrl }>
							{ __( 'Upgrade →', 'plume' ) }
						</a>
					</p>
				) }

				{ API_KEY_PROVIDERS.map( ( { id, label } ) => (
					<div
						key={ id }
						className="plume-field-row plume-field-row--key"
					>
						<div className="plume-field-input-group">
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
			<section className="plume-settings-section">
				<h3 className="plume-settings-section-title">
					Ollama (Self-hosted)
				</h3>

				<div className="plume-field-row plume-field-row--key">
					<div className="plume-field-input-group">
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
