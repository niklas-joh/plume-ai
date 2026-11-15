import { useState } from '@wordpress/element';
import { TextareaControl, Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
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
		<div className="plume-voice-tab">
			<section className="plume-settings-section">
				<div className="plume-settings-section-header">
					<h3 className="plume-settings-section-title">
						<Mic size={ 14 } />
						{ __( 'Site Voice & Persona', 'plume' ) }
					</h3>
					<p className="plume-settings-section-desc">
						{ __(
							'Define the writing style, tone, and persona the AI should adopt across all features. These instructions are applied site-wide.',
							'plume'
						) }
					</p>
				</div>

				<div
					className={ `plume-voice-textarea-wrap${
						isOverLimit ? ' is-error' : ''
					}` }
				>
					<TextareaControl
						rows={ 10 }
						value={ value }
						placeholder={ __(
							'Describe the writing style and persona for AI responses…',
							'plume'
						) }
						onChange={ handleChange }
						__nextHasNoMarginBottom
					/>
				</div>

				<div className="plume-voice-footer">
					<span
						className={ `plume-char-count${
							isOverLimit ? ' plume-char-count--error' : ''
						}` }
					>
						{ sprintf(
							/* translators: 1: number of characters typed, 2: maximum number of characters allowed. */
							__( '%1$d / %2$d characters', 'plume' ),
							charCount,
							MAX_CHARS
						) }
					</span>

					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving || ! isDirty || isOverLimit }
						onClick={ handleSave }
					>
						{ isSaving
							? __( 'Saving…', 'plume' )
							: __( 'Save Voice', 'plume' ) }
					</Button>
				</div>
			</section>
		</div>
	);
}
