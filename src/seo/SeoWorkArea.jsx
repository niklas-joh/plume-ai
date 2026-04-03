import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Loader2 } from 'lucide-react';
import DOMPurify from 'dompurify';

const { nonce, restUrl, adminUrl = '/wp-admin/' } = window.wpAiMindData ?? {};

const EMPTY_FIELDS = {
	meta_title: '',
	og_description: '',
	excerpt: '',
	alt_text: '',
};

export default function SeoWorkArea( { post, onClose, onUpdate } ) {
	const [ fields, setFields ] = useState( EMPTY_FIELDS );
	const [ hasGenerated, setHasGenerated ] = useState( false );
	const [ confirmReplace, setConfirmReplace ] = useState( false );
	const [ generating, setGenerating ] = useState( false );
	const [ applying, setApplying ] = useState( false );
	const [ error, setError ] = useState( null );

	const editUrl = `${ adminUrl }post.php?post=${ post.id }&action=edit`;

	const setField = ( key ) => ( e ) =>
		setFields( ( f ) => ( { ...f, [ key ]: e.target.value } ) );

	const handleGenerate = async () => {
		if ( hasGenerated && ! confirmReplace ) {
			setConfirmReplace( true );
			return;
		}
		setConfirmReplace( false );
		setGenerating( true );
		setError( null );
		try {
			const data = await apiFetch( {
				url: `${ restUrl }/seo/generate`,
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce },
				data: { post_id: post.id },
			} );
			setFields( {
				meta_title: data.meta_title ?? '',
				og_description: data.og_description ?? '',
				excerpt: data.excerpt ?? '',
				alt_text: data.alt_text ?? '',
			} );
			setHasGenerated( true );
		} catch ( e ) {
			setError( e.message ?? 'Generation failed.' );
		} finally {
			setGenerating( false );
		}
	};

	const handleApply = async () => {
		setApplying( true );
		setError( null );
		try {
			await apiFetch( {
				url: `${ restUrl }/seo/apply`,
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce },
				data: { post_id: post.id, ...fields },
			} );
			const prev = post.wpaim_seo_status ?? {};
			onUpdate( {
				id: post.id,
				wpaim_seo_status: {
					meta_title: fields.meta_title ? 'filled' : prev.meta_title,
					og_description: fields.og_description
						? 'filled'
						: prev.og_description,
					excerpt: fields.excerpt ? 'filled' : prev.excerpt,
					alt_text: fields.alt_text ? 'filled' : prev.alt_text,
				},
			} );
			onClose();
		} catch ( e ) {
			setError( e.message ?? 'Apply failed.' );
		} finally {
			setApplying( false );
		}
	};

	const inputClass = ( base = 'wpaim-field-input' ) =>
		`${ base }${ hasGenerated ? ' is-generated' : '' }`;

	return (
		<div className="wpaim-work-area">
			<div className="wpaim-work-header">
				<span
					className="wpaim-work-title"
					dangerouslySetInnerHTML={ {
						__html: DOMPurify.sanitize( post.title.rendered ),
					} }
				/>
				<button
					className="button button-primary"
					onClick={ handleGenerate }
					disabled={ generating }
				>
					{ generating ? (
						<>
							<Loader2 size={ 12 } className="wpaim-spin" />{ ' ' }
							Generating…
						</>
					) : (
						'✦ Generate SEO'
					) }
				</button>
			</div>

			{ confirmReplace && (
				<div className="wpaim-confirm-replace">
					<span>Replace current suggestions?</span>
					<button
						className="button button-small"
						onClick={ handleGenerate }
					>
						Yes, replace
					</button>
					<button
						className="button button-small"
						onClick={ () => setConfirmReplace( false ) }
					>
						Cancel
					</button>
				</div>
			) }

			<div className="wpaim-seo-fields-grid">
				<div className="wpaim-field">
					<label
						htmlFor="seo-meta-title"
						className="wpaim-field-label"
					>
						Meta title
						<span className="wpaim-char-count">
							{ fields.meta_title.length } / 60
						</span>
					</label>
					<input
						id="seo-meta-title"
						type="text"
						className={ inputClass() }
						value={ fields.meta_title }
						onChange={ setField( 'meta_title' ) }
						placeholder="AI will generate this…"
					/>
				</div>

				<div className="wpaim-field">
					<label htmlFor="seo-og-desc" className="wpaim-field-label">
						OG description
						<span className="wpaim-char-count">
							{ fields.og_description.length } / 160
						</span>
					</label>
					<input
						id="seo-og-desc"
						type="text"
						className={ inputClass() }
						value={ fields.og_description }
						onChange={ setField( 'og_description' ) }
						placeholder="AI will generate this…"
					/>
				</div>

				<div className="wpaim-field wpaim-field--full">
					<label htmlFor="seo-excerpt" className="wpaim-field-label">
						Excerpt
					</label>
					<textarea
						id="seo-excerpt"
						className={ inputClass() }
						value={ fields.excerpt }
						onChange={ setField( 'excerpt' ) }
						placeholder="AI will generate this…"
						rows={ 3 }
					/>
				</div>

				<div className="wpaim-field wpaim-field--full">
					<label htmlFor="seo-alt-text" className="wpaim-field-label">
						Featured image alt text
					</label>
					<input
						id="seo-alt-text"
						type="text"
						className={ inputClass() }
						value={ fields.alt_text }
						onChange={ setField( 'alt_text' ) }
						placeholder="AI will generate this…"
					/>
				</div>
			</div>

			{ error && <p className="wpaim-work-error">{ error }</p> }

			<div className="wpaim-work-actions">
				<a
					href={ editUrl }
					target="_blank"
					rel="noreferrer"
					className="wpaim-action-link"
				>
					Edit post →
				</a>
				<button
					className="button"
					onClick={ () => {
						setConfirmReplace( false );
						onClose();
					} }
					disabled={ applying }
				>
					Discard
				</button>
				<button
					className="button button-primary"
					onClick={ handleApply }
					disabled={ applying || ! hasGenerated }
				>
					{ applying ? 'Applying…' : '✓ Apply all' }
				</button>
			</div>
		</div>
	);
}
