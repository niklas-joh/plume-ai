import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Cpu, ChevronRight, ChevronLeft } from 'lucide-react';
import { storageGet, storageSet } from '../../utils/storage';

const STORAGE_KEY = 'plume_advanced_model';

const PROVIDER_LABELS = {
	claude: 'Claude',
	openai: 'OpenAI',
	gemini: 'Gemini',
	ollama: 'Ollama',
};

/**
 * Provider and model selector with a simple/advanced toggle.
 *
 * In simple mode, shows the plugin's configured default model label.
 * In advanced mode, exposes provider and per-provider model dropdowns.
 * The advanced state is persisted to localStorage. The Advanced toggle is
 * disabled for non-Pro users with a tooltip explaining the restriction.
 *
 * @param {Object}   props
 * @param {Array}    props.providers         Array of provider objects from the /providers endpoint.
 * @param {string}   props.selectedProvider  Currently selected provider slug.
 * @param {string}   props.selectedModel     Currently selected model ID, or empty for the provider default.
 * @param {Function} props.onProviderChange  Called with the new provider slug when changed.
 * @param {Function} props.onModelChange     Called with the new model ID when changed.
 * @param {boolean}  [props.isPro]           Whether the current user has a Pro tier.
 * @return {ReactElement}
 */
export default function ModelSelector( {
	providers,
	selectedProvider,
	selectedModel,
	onProviderChange,
	onModelChange,
	isPro = false,
} ) {
	const { defaultModelLabel = 'AI' } = window.plumeData || {};

	const [ isAdvanced, setIsAdvanced ] = useState(
		() => isPro && storageGet( STORAGE_KEY ) === '1'
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
			<div className="plume-panel-label">Model</div>

			{ ! isAdvanced ? (
				<div className="plume-model-simple">
					<div className="plume-model-selector__row">
						<Cpu size={ 12 } strokeWidth={ 1.5 } />
						<span className="plume-model-default-label">
							Plugin default — { defaultModelLabel }
						</span>
					</div>
					<button
						className="plume-model-advanced-toggle"
						type="button"
						onClick={ () => toggleAdvanced( true ) }
						disabled={ ! isPro }
						title={
							isPro
								? undefined
								: __(
										'Upgrade to Pro to select providers and models',
										'plume'
								  )
						}
					>
						Advanced{ ' ' }
						<ChevronRight size={ 11 } strokeWidth={ 1.5 } />
					</button>
				</div>
			) : (
				<div className="plume-model-selector">
					<div className="plume-model-selector__row">
						<Cpu size={ 12 } strokeWidth={ 1.5 } />
						<select
							aria-label="AI provider"
							className="plume-select"
							value={ selectedProvider }
							onChange={ ( e ) => {
								onProviderChange( e.target.value );
								onModelChange( '' );
							} }
						>
							{ providers.map( ( p ) => (
								<option key={ p.slug } value={ p.slug }>
									{ PROVIDER_LABELS[ p.slug ] || p.slug }
								</option>
							) ) }
						</select>
					</div>
					{ active && ! active.is_available && (
						<p className="plume-model-no-key">
							No API key configured —{ ' ' }
							<a href="options-general.php?page=plume-settings">
								Settings
							</a>
						</p>
					) }
					{ models.length > 0 && active?.is_available && (
						<select
							aria-label="Model"
							className="plume-select plume-select--sm"
							value={ selectedModel }
							onChange={ ( e ) =>
								onModelChange( e.target.value )
							}
						>
							<option value="">Default model</option>
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
						<ChevronLeft size={ 11 } strokeWidth={ 1.5 } /> Simple
					</button>
				</div>
			) }
		</div>
	);
}
