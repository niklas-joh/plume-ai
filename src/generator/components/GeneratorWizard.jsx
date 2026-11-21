import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	TextControl,
	SelectControl,
	Button,
	Notice,
} from '@wordpress/components';
import { Wand2, Loader2, CheckCircle2, ExternalLink } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import { getErrorMessage } from '../../shared/errors';
import PageHeader from '../../shared/PageHeader';
import Screen from '../../shared/Screen';
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
 * The shared page header is shown on every step.
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

	const header = (
		<PageHeader
			title={ __( 'Post Generator', 'plume' ) }
			description={ __(
				'Describe your post and AI will write a full draft.',
				'plume'
			) }
		/>
	);

	if ( step === 2 ) {
		return (
			<Screen header={ header } className="plume-generator">
				<div
					className="plume-generator__card plume-generator__status plume-measure"
					role="status"
				>
					<Loader2
						size={ 32 }
						className="plume-spin plume-generator__accent"
					/>
					<p className="plume-generator__muted">
						{ __(
							'Generating your post — a long draft can take up to two minutes…',
							'plume'
						) }
					</p>
				</div>
			</Screen>
		);
	}

	if ( step === 3 && result ) {
		return (
			<Screen header={ header } className="plume-generator">
				<div className="plume-measure">
					<div className="plume-generator__success">
						<CheckCircle2
							size={ 40 }
							className="plume-generator__accent"
						/>
						<h2>{ __( 'Post Generated!', 'plume' ) }</h2>
						<p>
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
							className="components-button is-primary plume-generator__edit-link"
						>
							<ExternalLink size={ 14 } />{ ' ' }
							{ __( 'Edit in WordPress', 'plume' ) }
						</a>
					</div>
					<div className="plume-generator__card plume-generator__preview-card">
						<h3 className="plume-generator__preview-title">
							{ __( 'Preview', 'plume' ) }
						</h3>
						<div
							className="plume-generator__preview"
							dangerouslySetInnerHTML={ {
								__html: result.content,
							} }
						/>
					</div>
					<div className="plume-generator__actions">
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
			</Screen>
		);
	}

	// Step 1 — Brief form
	return (
		<Screen header={ header } className="plume-generator">
			<div className="plume-measure">
				{ outOfCredits && (
					<OutOfCreditsNotice
						websiteUrl={
							window.plumeData?.websiteUrl ?? DEFAULT_WEBSITE_URL
						}
						screen="generator"
						settingsUrl={ window.plumeData?.settingsUrl }
					/>
				) }

				{ error && (
					<Notice
						status="error"
						isDismissible={ false }
						className="plume-generator__error"
					>
						{ error }
					</Notice>
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
							className="plume-generator__generate"
						>
							<Wand2 size={ 14 } />{ ' ' }
							{ __( 'Generate Post', 'plume' ) }
						</Button>
					</div>
				</div>
			</div>
		</Screen>
	);
}
