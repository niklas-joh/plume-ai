import { useState } from '@wordpress/element';
import { TextareaControl, Button } from '@wordpress/components';
import { Mic } from 'lucide-react';

const MAX_CHARS = 2000;

/**
 * Settings tab for defining the site-wide AI writing voice and persona.
 *
 * Tracks local dirty state so the textarea is responsive while the REST
 * save is in flight. Enforces a 2 000-character hard limit and shows a
 * live character counter that turns red when exceeded.
 *
 * @param {Object}   props
 * @param {Object}   props.settings      Full settings object from the REST API.
 * @param {Function} props.saveSettings  Persists a partial settings patch via POST.
 * @param {boolean}  props.isSaving      True while a save request is in flight.
 * @return {ReactElement}
 */
export default function VoiceTab( { settings, saveSettings, isSaving } ) {
	const [ value, setValue ] = useState( settings?.site_voice ?? '' );
	const [ isDirty, setIsDirty ] = useState( false );

	// Sync if settings load after mount
	const savedValue = settings?.site_voice ?? '';
	if ( ! isDirty && value !== savedValue ) {
		setValue( savedValue );
	}

	function handleChange( val ) {
		setValue( val );
		setIsDirty( true );
	}

	function handleSave() {
		saveSettings( { site_voice: value } );
		setIsDirty( false );
	}

	const charCount = value.length;
	const isOverLimit = charCount > MAX_CHARS;

	return (
		<div className="wpaim-voice-tab">
			<section className="wpaim-settings-section">
				<div className="wpaim-settings-section-header">
					<h3 className="wpaim-settings-section-title">
						<Mic size={ 14 } />
						Site Voice &amp; Persona
					</h3>
					<p className="wpaim-settings-section-desc">
						Define the writing style, tone, and persona the AI
						should adopt across all features. These instructions are
						applied site-wide.
					</p>
				</div>

				<div
					className={ `wpaim-voice-textarea-wrap${
						isOverLimit ? ' is-error' : ''
					}` }
				>
					<TextareaControl
						rows={ 10 }
						value={ value }
						placeholder="Describe the writing style and persona for AI responses…"
						onChange={ handleChange }
						__nextHasNoMarginBottom
					/>
				</div>

				<div className="wpaim-voice-footer">
					<span
						className={ `wpaim-char-count${
							isOverLimit ? ' wpaim-char-count--error' : ''
						}` }
					>
						{ charCount } / { MAX_CHARS } characters
					</span>

					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving || ! isDirty || isOverLimit }
						onClick={ handleSave }
					>
						{ isSaving ? 'Saving…' : 'Save Voice' }
					</Button>
				</div>
			</section>
		</div>
	);
}
