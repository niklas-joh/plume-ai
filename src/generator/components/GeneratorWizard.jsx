import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl, SelectControl, Button } from '@wordpress/components';
import { Wand2, Loader2, CheckCircle2, ExternalLink } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import { getErrorMessage } from '../../shared/errors';
import { isOutOfCreditsError } from '../../shared/credits';
import OutOfCreditsNotice from '../../shared/OutOfCreditsNotice';
import { DEFAULT_WEBSITE_URL } from '../../shared/constants';

const TONES = [
	{ value: 'professional', label: __( 'Professional', 'plume' ) },
	{ value: 'casual', label: __( 'Casual', 'plume' ) },
	{ value: 'friendly', label: __( 'Friendly', 'plume' ) },
	{ value: 'authoritative', label: __( 'Authoritative', 'plume' ) },
	{ value: 'witty', label: __( 'Witty', 'plume' ) },
];
const LENGTHS = [
	{ value: 'short', label: __( 'Short (300–500 words)', 'plume' ) },
	{ value: 'medium', label: __( 'Medium (600–900 words)', 'plume' ) },
	{ value: 'long', label: __( 'Long (1200–1800 words)', 'plume' ) },
];

/**
 * Multi-step wizard for AI-assisted full post generation.
 *
 * Manages a three-state machine: step 1 (brief form) → step 2 (generating
 * spinner) → step 3 (success screen with preview). On success, the generated
 * post already exists in WordPress and the user is given a direct edit link.
 *
 * @return {ReactElement}
 */
export default function GeneratorWizard() {
	const [ step, setStep ] = useState( 1 ); // 1=brief, 2=generating, 3=done
	const [ form, setForm ] = useState( {
		title: '',
		keywords: '',
		tone: 'professional',
		length: 'medium',
	} );
	const [ result, setResult ] = useState( null ); // { post_id, edit_url, content, credits_used }
	const [ error, setError ] = useState( null );
	const [ outOfCredits, setOutOfCredits ] = useState( false );

	function update( field, value ) {
		setForm( ( prev ) => ( { ...prev, [ field ]: value } ) );
	}

	async function generate() {
		if ( ! form.title.trim() ) {
			return;
		}
		setStep( 2 );
		setError( null );
		setOutOfCredits( false );
		try {
			const res = await apiFetch( {
				path: '/plume/v1/generate',
				method: 'POST',
				data: form,
			} );
			setResult( res );
			setStep( 3 );
		} catch ( e ) {
			if ( isOutOfCreditsError( e ) ) {
				setOutOfCredits( true );
			} else {
				setError(
					getErrorMessage(
						e,
						__( 'Generation failed. Please try again.', 'plume' )
					)
				);
			}
			setStep( 1 );
		}
	}

	if ( step === 2 ) {
		return (
			<div className="plume-generator">
				<div
					className="plume-generator__card"
					style={ { textAlign: 'center', padding: 'var(--space-8)' } }
				>
					<Loader2
						size={ 32 }
						className="plume-spin"
						style={ { color: 'var(--wp-admin-theme-color)' } }
					/>
					<p
						style={ {
							marginTop: 'var(--space-4)',
							color: 'var(--color-text-muted)',
						} }
					>
						{ __(
							'Generating your post — a long draft can take up to two minutes…',
							'plume'
						) }
					</p>
				</div>
			</div>
		);
	}

	if ( step === 3 && result ) {
		return (
			<div className="plume-generator">
				<div className="plume-generator__success">
					<CheckCircle2
						size={ 40 }
						style={ { color: 'var(--wp-admin-theme-color)' } }
					/>
					<h2 style={ { marginTop: 'var(--space-3)' } }>
						{ __( 'Post Generated!', 'plume' ) }
					</h2>
					<p style={ { color: 'var(--color-text-muted)' } }>
						{ sprintf(
							/* translators: %s: number of credits the generation used. */
							_n(
								'%s credit used',
								'%s credits used',
								result.credits_used,
								'plume'
							),
							result.credits_used
						) }
					</p>
					<a
						href={ result.edit_url }
						className="plume-generator__btn plume-generator__btn--primary"
						style={ {
							display: 'inline-flex',
							alignItems: 'center',
							gap: 'var(--space-2)',
							marginTop: 'var(--space-4)',
							textDecoration: 'none',
						} }
					>
						<ExternalLink size={ 14 } />{ ' ' }
						{ __( 'Edit in WordPress', 'plume' ) }
					</a>
				</div>
				<div
					className="plume-generator__card"
					style={ { marginTop: 'var(--space-4)' } }
				>
					<h3
						style={ {
							marginBottom: 'var(--space-2)',
							fontSize: 'var(--text-sm)',
							color: 'var(--color-text-muted)',
						} }
					>
						{ __( 'Preview', 'plume' ) }
					</h3>
					<div
						className="plume-generator__preview"
						dangerouslySetInnerHTML={ { __html: result.content } }
					/>
				</div>
				<div
					className="plume-generator__actions"
					style={ { marginTop: 'var(--space-4)' } }
				>
					<Button
						variant="tertiary"
						onClick={ () => {
							setStep( 1 );
							setResult( null );
						} }
					>
						{ __( 'Generate another', 'plume' ) }
					</Button>
				</div>
			</div>
		);
	}

	// Step 1 — Brief form
	return (
		<div className="plume-generator">
			<div className="plume-generator__header">
				<h1
					style={ {
						display: 'flex',
						alignItems: 'center',
						gap: 'var(--space-2)',
					} }
				>
					<Wand2
						size={ 24 }
						style={ { color: 'var(--wp-admin-theme-color)' } }
					/>{ ' ' }
					{ __( 'Post Generator', 'plume' ) }
				</h1>
				<p style={ { color: 'var(--color-text-muted)' } }>
					{ __(
						'Describe your post and AI will write a full draft.',
						'plume'
					) }
				</p>
			</div>

			{ outOfCredits && (
				<OutOfCreditsNotice
					websiteUrl={
						window.plumeData?.websiteUrl ?? DEFAULT_WEBSITE_URL
					}
					settingsUrl={ window.plumeData?.settingsUrl }
				/>
			) }

			{ error && (
				<div
					style={ {
						background:
							'var(--color-error-subtle, rgba(220,38,38,0.1))',
						border: '1px solid var(--color-error)',
						borderRadius: 'var(--radius-md)',
						padding: 'var(--space-3)',
						marginBottom: 'var(--space-4)',
						color: 'var(--color-error)',
						fontSize: 'var(--text-sm)',
					} }
				>
					{ error }
				</div>
			) }

			<div className="plume-generator__card">
				<TextControl
					label={ __( 'Post title *', 'plume' ) }
					value={ form.title }
					onChange={ ( val ) => update( 'title', val ) }
					placeholder={ __(
						'e.g. 10 Tips for Better Sleep',
						'plume'
					) }
					__nextHasNoMarginBottom
				/>

				<TextControl
					label={ __( 'Keywords', 'plume' ) }
					value={ form.keywords }
					onChange={ ( val ) => update( 'keywords', val ) }
					placeholder={ __(
						'e.g. sleep hygiene, circadian rhythm, melatonin',
						'plume'
					) }
					__nextHasNoMarginBottom
				/>

				<div className="plume-generator__options">
					<SelectControl
						label={ __( 'Tone', 'plume' ) }
						options={ TONES }
						value={ form.tone }
						onChange={ ( val ) => update( 'tone', val ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Length', 'plume' ) }
						options={ LENGTHS }
						value={ form.length }
						onChange={ ( val ) => update( 'length', val ) }
						__nextHasNoMarginBottom
					/>
				</div>

				<div className="plume-generator__actions">
					<Button
						variant="primary"
						isBusy={ step === 2 }
						disabled={ ! form.title.trim() }
						onClick={ generate }
						style={ {
							display: 'inline-flex',
							alignItems: 'center',
							gap: 'var(--space-2)',
						} }
					>
						<Wand2 size={ 14 } /> { __( 'Generate Post', 'plume' ) }
					</Button>
				</div>
			</div>
		</div>
	);
}
