import { useState } from '@wordpress/element';
import { SelectControl, TextControl, Button } from '@wordpress/components';

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

				<SelectControl
					label="Default AI Provider"
					options={ PROVIDER_OPTIONS }
					value={ settings?.default_provider ?? '' }
					onChange={ ( val ) =>
						saveSettings( { default_provider: val } )
					}
					__nextHasNoMarginBottom
				/>

				<SelectControl
					label="Image Provider"
					options={ IMAGE_PROVIDER_OPTIONS }
					value={ settings?.image_provider ?? '' }
					onChange={ ( val ) =>
						saveSettings( { image_provider: val } )
					}
					__nextHasNoMarginBottom
				/>
			</section>

			{ /* API key inputs */ }
			<section className="wpaim-settings-section">
				<h3 className="wpaim-settings-section-title">API Keys</h3>

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
							/>
							<Button
								variant="primary"
								disabled={
									isSaving || dirty[ id ] === undefined
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
