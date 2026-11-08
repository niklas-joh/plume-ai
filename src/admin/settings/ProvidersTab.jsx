import { useState } from '@wordpress/element';
import {
	SelectControl,
	TextControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

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

// Providers that can be served by the Plume service (mirrors ProviderFactory::PROXY_CAPABLE).
const MANAGED_CAPABLE = [ 'claude', 'openai', 'gemini' ];

const IMAGE_PROVIDER_OPTIONS = [
	{ value: 'openai', label: 'OpenAI (DALL·E)' },
	{ value: 'gemini', label: 'Google Gemini' },
];

/**
 * Settings tab for configuring AI providers, API keys, and the Ollama endpoint.
 *
 * API keys are stored per-provider in a dirty-state map and only persisted
 * when the user explicitly clicks Save, preventing accidental overwrites.
 * Providers, models, and API keys are configurable on every tier (WP.org
 * Guideline 5) — the managed proxy enforces each plan's model catalogue
 * service-side.
 *
 * `settings.managed_providers` lists the providers the Plume service serves for this
 * site's tier. `null` or absent means no restriction (pro_byok, or an older response).
 * A listed-out provider is disabled in the default-provider select unless the site has
 * its own key for it, and a notice explains why when it is the stored default.
 *
 * @param {Object}    props
 * @param {Object}    props.settings                    Full settings object from the REST API.
 * @param {?string[]} props.settings.managed_providers  Provider slugs served by the Plume service, or null for no restriction.
 * @param {Function}  props.saveSettings                Persists a partial settings patch via POST.
 * @param {boolean}   props.isSaving                    True while a save request is in flight.
 * @return {ReactElement}
 */
export default function ProvidersTab( { settings, saveSettings, isSaving } ) {
	const apiKeys = settings?.api_keys ?? {};
	const [ dirty, setDirty ] = useState( {} ); // { [provider]: string }

	const managed = settings?.managed_providers;
	// `apiKeys[ id ]` is a mask string when a key is set (SettingsRestController).
	const needsOwnKey = ( id ) =>
		Array.isArray( managed ) &&
		MANAGED_CAPABLE.includes( id ) &&
		! managed.includes( id ) &&
		! apiKeys[ id ];
	const providerOptions = PROVIDER_OPTIONS.map( ( option ) =>
		needsOwnKey( option.value )
			? {
					...option,
					disabled: true,
					label: sprintf(
						/* translators: %s: AI provider name, e.g. "OpenAI". */
						__( '%s (needs your own API key)', 'plume' ),
						option.label
					),
				}
			: option
	);
	const defaultProvider = settings?.default_provider ?? '';
	const defaultProviderName = PROVIDER_OPTIONS.find(
		( option ) => option.value === defaultProvider
	)?.label;
	const hasAnyOwnKey = MANAGED_CAPABLE.some( ( id ) => !! apiKeys[ id ] );

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

				<fieldset>
					<SelectControl
						label={ __( 'Default AI Provider', 'plume' ) }
						options={ providerOptions }
						value={ defaultProvider }
						onChange={ ( val ) =>
							saveSettings( { default_provider: val } )
						}
						__nextHasNoMarginBottom
					/>

					{ needsOwnKey( defaultProvider ) &&
						( hasAnyOwnKey ? (
							<Notice status="info" isDismissible={ false }>
								{ sprintf(
									/* translators: %1$s: AI provider name, e.g. "OpenAI". */
									__(
										'%1$s is not available through the Plume service. Add your own %1$s API key or choose a different provider.',
										'plume'
									),
									defaultProviderName
								) }
							</Notice>
						) : (
							// TEMPORARY(#1020): only true while the Worker serves keyless
							// sites with Claude. On removal: delete this branch so the
							// permanent notice above is shown whether or not a key is set.
							<Notice status="info" isDismissible={ false }>
								{ sprintf(
									/* translators: %1$s: AI provider name, e.g. "OpenAI". */
									__(
										'%1$s is temporarily unavailable through the Plume service. Chats use Claude until you add your own %1$s API key below.',
										'plume'
									),
									defaultProviderName
								) }
							</Notice>
						) ) }

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
