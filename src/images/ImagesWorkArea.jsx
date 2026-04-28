import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Loader2 } from 'lucide-react';
import DOMPurify from 'dompurify';

const { nonce, restUrl, adminUrl = '/wp-admin/' } = window.wpAiMindData ?? {};

const ASPECT_RATIOS = [ '16:9', '1:1', '4:3', '9:16' ];

/**
 * Expanded work area for generating and setting a featured image on a post.
 *
 * Sends a generation request to the /images/generate endpoint and renders a
 * selectable grid of results. On confirmation, sets the chosen image as the
 * post's featured media via the post's own REST self-link so the correct
 * post-type endpoint is used regardless of post type.
 *
 * @param {Object}   props
 * @param {Object}   props.post      WordPress post object with `_links.self` populated.
 * @param {Function} props.onClose   Called when the work area should be dismissed.
 * @param {Function} props.onUpdate  Called with a partial post patch after the featured image is set.
 * @return {ReactElement}
 */
export default function ImagesWorkArea( { post, onClose, onUpdate } ) {
	const [ prompt, setPrompt ] = useState( '' );
	const [ aspectRatio, setAspectRatio ] = useState( '16:9' );
	const [ count, setCount ] = useState( 2 );
	const [ images, setImages ] = useState( [] );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ generating, setGenerating ] = useState( false );
	const [ setting, setSetting ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ warning, setWarning ] = useState( null );

	const editUrl = `${ adminUrl }post.php?post=${ post.id }&action=edit`;

	const handleGenerate = async () => {
		if ( ! prompt.trim() ) {
			return;
		}
		setGenerating( true );
		setError( null );
		setWarning( null );
		setImages( [] );
		setSelectedId( null );
		try {
			const data = await apiFetch( {
				url: `${ restUrl }/images/generate`,
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce },
				data: { prompt, aspect_ratio: aspectRatio, count },
			} );
			setImages( data.images ?? [] );
			if ( data.errors?.length ) {
				const failCount = data.errors.length;
				const okCount = data.images?.length ?? 0;
				setWarning(
					`${ failCount } of ${ failCount + okCount } image${
						failCount > 1 ? 's' : ''
					} failed to generate.`
				);
			}
		} catch ( e ) {
			setError( e.message ?? 'Generation failed.' );
		} finally {
			setGenerating( false );
		}
	};

	const handleSetFeatured = async () => {
		if ( ! selectedId ) {
			return;
		}
		setSetting( true );
		setError( null );
		try {
			const selfHref = post._links?.self?.[ 0 ]?.href;
			if ( ! selfHref ) {
				throw new Error(
					`Cannot determine the REST endpoint for post type "${ post.type }".`
				);
			}
			await apiFetch( {
				url: selfHref,
				method: 'POST',
				data: { featured_media: selectedId },
			} );
			const selected = images.find(
				( img ) => img.attachment_id === selectedId
			);
			onUpdate( {
				id: post.id,
				featured_media: selectedId,
				_embedded: {
					...post._embedded,
					'wp:featuredmedia': [
						{
							source_url: selected?.url ?? '',
							media_details: {
								sizes: {
									thumbnail: {
										source_url:
											selected?.thumbnail_url ??
											selected?.url ??
											'',
									},
								},
							},
						},
					],
				},
			} );
			onClose();
		} catch ( e ) {
			setError( e.message ?? 'Failed to set featured image.' );
		} finally {
			setSetting( false );
		}
	};

	return (
		<div className="wpaim-work-area">
			<div className="wpaim-work-header">
				<span
					className="wpaim-work-title"
					dangerouslySetInnerHTML={ {
						__html: DOMPurify.sanitize( post.title.rendered ),
					} }
				/>
			</div>

			<div className="wpaim-images-prompt-row">
				<textarea
					className="wpaim-prompt-input"
					placeholder="Describe the image you want to generate…"
					value={ prompt }
					onChange={ ( e ) => setPrompt( e.target.value ) }
					rows={ 4 }
				/>
				<div className="wpaim-images-controls">
					<div className="wpaim-control">
						<label
							htmlFor="img-aspect-ratio"
							className="wpaim-field-label"
						>
							Aspect ratio
						</label>
						<select
							id="img-aspect-ratio"
							className="wpaim-field-input"
							value={ aspectRatio }
							onChange={ ( e ) =>
								setAspectRatio( e.target.value )
							}
						>
							{ ASPECT_RATIOS.map( ( r ) => (
								<option key={ r } value={ r }>
									{ r }
								</option>
							) ) }
						</select>
					</div>
					<div className="wpaim-control">
						<span className="wpaim-field-label">Count</span>
						<div className="wpaim-count-pills">
							{ [ 1, 2, 3 ].map( ( n ) => (
								<button
									key={ n }
									className={ `wpaim-pill${
										count === n ? ' is-active' : ''
									}` }
									onClick={ () => setCount( n ) }
								>
									{ n }
								</button>
							) ) }
						</div>
					</div>
					<button
						className="button button-primary"
						onClick={ handleGenerate }
						disabled={ generating || ! prompt.trim() }
					>
						{ generating ? (
							<>
								<Loader2 size={ 12 } className="wpaim-spin" />{ ' ' }
								Generating…
							</>
						) : (
							'✦ Generate'
						) }
					</button>
				</div>
			</div>

			{ warning && (
				<p className="wpaim-work-warning">
					⚠ { warning }
					<button
						className="wpaim-dismiss"
						onClick={ () => setWarning( null ) }
					>
						✕
					</button>
				</p>
			) }

			{ images.length > 0 && (
				<div className="wpaim-image-grid">
					{ images.map( ( img ) => (
						<div
							key={ img.attachment_id }
							className={ `wpaim-image-card${
								selectedId === img.attachment_id
									? ' is-selected'
									: ''
							}` }
							onClick={ () => setSelectedId( img.attachment_id ) }
							role="button"
							tabIndex={ 0 }
							onKeyDown={ ( e ) =>
								e.key === 'Enter' &&
								setSelectedId( img.attachment_id )
							}
						>
							<img
								src={ img.url }
								alt={ prompt }
								className="wpaim-image-thumb"
							/>
							{ selectedId === img.attachment_id && (
								<span className="wpaim-selected-badge">
									✓ Selected
								</span>
							) }
							<div className="wpaim-image-footer">
								<a
									href={ `${ adminUrl }post.php?post=${ img.attachment_id }&action=edit` }
									target="_blank"
									rel="noreferrer"
									onClick={ ( e ) => e.stopPropagation() }
								>
									View →
								</a>
							</div>
						</div>
					) ) }
				</div>
			) }

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
					onClick={ onClose }
					disabled={ setting }
				>
					Discard
				</button>
				<button
					className="button button-primary"
					onClick={ handleSetFeatured }
					disabled={ setting || ! selectedId }
				>
					{ setting ? 'Setting…' : '✓ Set as featured image' }
				</button>
			</div>
		</div>
	);
}
