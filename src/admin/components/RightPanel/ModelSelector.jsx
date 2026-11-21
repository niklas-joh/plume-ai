import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Cpu, ChevronRight, ChevronLeft } from 'lucide-react';
import { storageGet, storageSet } from '../../utils/storage';

const STORAGE_KEY = 'plume_advanced_model';

/**
 * Display name for a provider slug. Brand names stay as they are; Ollama also says
 * that it runs on the user's own machine. An unknown slug gets a neutral name so a
 * raw slug never reaches the screen.
 *
 * @param {string} slug Provider slug from the /providers endpoint.
 * @return {string} Name to show.
 */
function providerLabel( slug ) {
	switch ( slug ) {
		case 'claude':
			return 'Claude';
		case 'openai':
			return 'OpenAI';
		case 'gemini':
			return 'Gemini';
		case 'ollama':
			return __( 'Ollama (self-hosted)', 'plume' );
		default:
			return __( 'Other provider', 'plume' );
	}
}

/**
 * Provider and model selector with a simple/advanced toggle.
 *
 * In simple mode, shows the plugin's configured default model label.
 * In advanced mode, exposes provider and per-provider model dropdowns.
 * The advanced state is persisted to localStorage. Available on every tier
 * (WP.org Guideline 5) — the managed proxy enforces each plan's model
 * catalogue service-side.
 *
 * The `providers` list is already filtered server-side: managed providers the Plume
 * service does not serve are omitted (unless the site has its own key), and a managed
 * provider's models are the Worker's allow-list. This component renders what it is given.
 *
 * @param {Object}   props
 * @param {Array}    props.providers         Array of provider objects from the /providers endpoint.
 * @param {string}   props.selectedProvider  Currently selected provider slug.
 * @param {string}   props.selectedModel     Currently selected model ID, or empty for the provider default.
 * @param {Function} props.onProviderChange  Called with the new provider slug when changed.
 * @param {Function} props.onModelChange     Called with the new model ID when changed.
 * @param {boolean}  props.justSaved         True briefly after a provider/model change is persisted.
 * @return {ReactElement}
 */
export default function ModelSelector( {
	providers,
	selectedProvider,
	selectedModel,
	onProviderChange,
	onModelChange,
	justSaved = false,
} ) {
	const { defaultModelLabel = __( 'AI', 'plume' ), settingsUrl = '' } =
		window.plumeData || {};

	const [ isAdvanced, setIsAdvanced ] = useState(
		() => storageGet( STORAGE_KEY ) === '1'
	);

	function toggleAdvanced( value ) {
		setIsAdvanced( value );
		storageSet( STORAGE_KEY, value ? '1' : '0' );
		if ( ! value ) {
			onProviderChange( '' );
			onModelChange( '' );
		}
	}

	const active = providers.find( ( p ) => p.slug === selectedProvider );
	const models = active ? Object.entries( active.models ) : [];

	return (
		<div className="plume-panel-section">
			<div className="plume-panel-label">{ __( 'Model', 'plume' ) }</div>

			{ ! isAdvanced ? (
				<div className="plume-model-simple">
					<div className="plume-model-selector__row plume-model-selector__row--default">
						<Cpu size={ 12 } strokeWidth={ 1.5 } />
						<div className="plume-model-default">
							<span className="plume-model-default__caption">
								{ __( 'Plugin default', 'plume' ) }
							</span>
							<span className="plume-model-default-label">
								{ defaultModelLabel }
							</span>
						</div>
					</div>
					<button
						className="plume-model-advanced-toggle"
						type="button"
						onClick={ () => toggleAdvanced( true ) }
					>
						{ __( 'Advanced', 'plume' ) }{ ' ' }
						<ChevronRight size={ 11 } strokeWidth={ 1.5 } />
					</button>
					{ justSaved && (
						<span className="plume-model-saved">
							{ __( 'Saved', 'plume' ) }
						</span>
					) }
				</div>
			) : (
				<div className="plume-model-selector">
					<div className="plume-model-selector__row">
						<Cpu size={ 12 } strokeWidth={ 1.5 } />
						<select
							aria-label={ __( 'AI provider', 'plume' ) }
							className="plume-select"
							value={ selectedProvider }
							onChange={ ( e ) => {
								onProviderChange( e.target.value );
								onModelChange( '' );
							} }
						>
							{ providers.map( ( p ) => (
								<option key={ p.slug } value={ p.slug }>
									{ providerLabel( p.slug ) }
								</option>
							) ) }
						</select>
					</div>
					{ active && ! active.is_available && (
						<p className="plume-model-no-key">
							{ __( 'No API key configured', 'plume' ) }
							{ settingsUrl && (
								<>
									{ ' — ' }
									<a href={ settingsUrl }>
										{ __( 'Settings', 'plume' ) }
									</a>
								</>
							) }
						</p>
					) }
					{ models.length > 0 && active?.is_available && (
						<select
							aria-label={ __( 'Model', 'plume' ) }
							className="plume-select plume-select--sm"
							value={ selectedModel }
							onChange={ ( e ) =>
								onModelChange( e.target.value )
							}
						>
							<option value="">
								{ __( 'Default model', 'plume' ) }
							</option>
							{ models.map( ( [ id, label ] ) => (
								<option key={ id } value={ id }>
									{ label }
								</option>
							) ) }
						</select>
					) }
					<button
						className="plume-model-advanced-toggle"
						type="button"
						onClick={ () => toggleAdvanced( false ) }
					>
						<ChevronLeft size={ 11 } strokeWidth={ 1.5 } />{ ' ' }
						{ __( 'Simple', 'plume' ) }
					</button>
					{ justSaved && (
						<span className="plume-model-saved">
							{ __( 'Saved', 'plume' ) }
						</span>
					) }
				</div>
			) }
		</div>
	);
}
