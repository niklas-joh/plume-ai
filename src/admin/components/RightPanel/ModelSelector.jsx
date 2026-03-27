import { useState } from '@wordpress/element';
import { Cpu, ChevronRight, ChevronLeft } from 'lucide-react';

const STORAGE_KEY = 'wpaim_advanced_model';

const PROVIDER_LABELS = {
	claude: 'Claude',
	openai: 'OpenAI',
	gemini: 'Gemini',
	ollama: 'Ollama',
};

export default function ModelSelector( {
	providers,
	selectedProvider,
	selectedModel,
	onProviderChange,
	onModelChange,
} ) {
	const { defaultModelLabel = 'AI' } = window.wpAiMindData || {};

	const [ isAdvanced, setIsAdvanced ] = useState(
		() => window.localStorage.getItem( STORAGE_KEY ) === '1'
	);

	function toggleAdvanced( value ) {
		setIsAdvanced( value );
		window.localStorage.setItem( STORAGE_KEY, value ? '1' : '0' );
		if ( ! value ) {
			onProviderChange( '' );
			onModelChange( '' );
		}
	}

	const active = providers.find( ( p ) => p.slug === selectedProvider );
	const models = active ? Object.entries( active.models ) : [];

	return (
		<div className="wpaim-panel-section">
			<div className="wpaim-panel-label">Model</div>

			{ ! isAdvanced ? (
				<div className="wpaim-model-simple">
					<div className="wpaim-model-selector__row">
						<Cpu size={ 12 } strokeWidth={ 1.5 } />
						<span className="wpaim-model-default-label">
							Plugin default — { defaultModelLabel }
						</span>
					</div>
					<button
						className="wpaim-model-advanced-toggle"
						type="button"
						onClick={ () => toggleAdvanced( true ) }
					>
						Advanced{ ' ' }
						<ChevronRight size={ 11 } strokeWidth={ 1.5 } />
					</button>
				</div>
			) : (
				<div className="wpaim-model-selector">
					<div className="wpaim-model-selector__row">
						<Cpu size={ 12 } strokeWidth={ 1.5 } />
						<select
							aria-label="AI provider"
							className="wpaim-select"
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
						<p className="wpaim-model-no-key">
							No API key configured —{ ' ' }
							<a href="options-general.php?page=wp-ai-mind-settings">
								Settings
							</a>
						</p>
					) }
					{ models.length > 0 && active?.is_available && (
						<select
							aria-label="Model"
							className="wpaim-select wpaim-select--sm"
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
						className="wpaim-model-advanced-toggle"
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
