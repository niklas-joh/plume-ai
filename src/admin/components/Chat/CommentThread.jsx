import { useState, useRef, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { MessageSquare, ChevronDown, ChevronUp } from 'lucide-react';

/**
 * Per-DiffBlock comment thread rendered below an Added paragraph.
 *
 * Supports saving multiple comments against a single changed block.
 * Notifies the parent whenever the dirty (unsaved input) state changes so
 * the drawer can block "Request revision" until all inputs are resolved.
 *
 * @param {Object}   props
 * @param {string}   props.diffBlockId        ID of the owning DiffBlock.
 * @param {Array}    props.comments           Saved comments for this block: `{ id, selectedText, text }[]`.
 * @param {Function} props.onSave             Called with `(diffBlockId, selectedText, text)` to save a new comment.
 * @param {Function} props.onDelete           Called with `(commentId)` to delete a saved comment.
 * @param {Function} props.onUnsavedChange    Called with `(diffBlockId, hasDirty: boolean)` on input state change.
 * @param {string}   [props.pendingAnchor]    Pre-populated selected text for the active unsaved input row.
 * @param {Function} [props.onAnchorConsumed] Called after the pending anchor is consumed into the input row.
 * @return {ReactElement}
 */
export default function CommentThread( {
	diffBlockId,
	comments,
	onSave,
	onDelete,
	onUnsavedChange,
	pendingAnchor,
	onAnchorConsumed,
} ) {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ inputText, setInputText ] = useState( '' );
	const [ activeAnchor, setActiveAnchor ] = useState( null );
	const inputRef = useRef( null );

	// When a text selection from DiffView triggers a new comment, expand and pre-fill anchor.
	useEffect( () => {
		if ( pendingAnchor ) {
			setActiveAnchor( pendingAnchor );
			setIsExpanded( true );
			onAnchorConsumed?.();
			// Focus the textarea on the next paint.
			setTimeout( () => inputRef.current?.focus(), 0 );
		}
	}, [ pendingAnchor ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Notify parent whenever the dirty state changes.
	useEffect( () => {
		onUnsavedChange( diffBlockId, inputText.trim().length > 0 );
	}, [ inputText, diffBlockId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const hasComments = comments.length > 0;

	function handleSave() {
		const text = inputText.trim();
		if ( ! text ) {
			return;
		}
		onSave( diffBlockId, activeAnchor ?? '', text );
		setInputText( '' );
		setActiveAnchor( null );
	}

	function handleInputKeyDown( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			handleSave();
		}
		if ( e.key === 'Escape' ) {
			setInputText( '' );
			setActiveAnchor( null );
		}
	}

	if ( ! hasComments && ! pendingAnchor && ! isExpanded ) {
		return null;
	}

	const toggleLabel = sprintf(
		/* translators: %d: number of comments */
		_n(
			'%d comment on this change',
			'%d comments on this change',
			comments.length,
			'plume'
		),
		comments.length
	);

	return (
		<div
			className="plume-comment-thread"
			role="region"
			aria-label={ __( 'Comments on this change', 'plume' ) }
		>
			{ hasComments && (
				<button
					type="button"
					className="plume-comment-thread__toggle"
					onClick={ () => setIsExpanded( ( v ) => ! v ) }
					aria-expanded={ isExpanded }
				>
					<MessageSquare size={ 12 } />
					<span>{ toggleLabel }</span>
					{ isExpanded ? (
						<ChevronUp size={ 12 } />
					) : (
						<ChevronDown size={ 12 } />
					) }
				</button>
			) }

			<div
				className={ `plume-comment-thread__body${
					isExpanded || pendingAnchor
						? ' plume-comment-thread__body--open'
						: ''
				}` }
			>
				{ comments.map( ( c ) => (
					<div key={ c.id } className="plume-comment-item">
						{ c.selectedText && (
							<span
								className="plume-comment-item__anchor"
								title={ c.selectedText }
							>
								{ c.selectedText.length > 40
									? c.selectedText.slice( 0, 40 ) + '…'
									: c.selectedText }
							</span>
						) }
						<p className="plume-comment-item__text">{ c.text }</p>
						<div className="plume-comment-item__actions">
							<button
								type="button"
								className="plume-btn plume-btn--ghost plume-btn--xs plume-btn--danger"
								onClick={ () => onDelete( c.id ) }
							>
								{ __( 'Delete', 'plume' ) }
							</button>
						</div>
					</div>
				) ) }

				<div className="plume-comment-item plume-comment-item--input">
					{ activeAnchor && (
						<span
							className="plume-comment-item__anchor"
							title={ activeAnchor }
						>
							{ activeAnchor.length > 40
								? activeAnchor.slice( 0, 40 ) + '…'
								: activeAnchor }
						</span>
					) }
					<textarea
						ref={ inputRef }
						className="plume-comment-item__textarea"
						value={ inputText }
						onChange={ ( e ) => setInputText( e.target.value ) }
						onKeyDown={ handleInputKeyDown }
						placeholder={ __( 'Add a comment…', 'plume' ) }
						rows={ 2 }
					/>
					{ inputText.trim().length > 0 && (
						<div className="plume-comment-item__actions">
							<button
								type="button"
								className="plume-btn plume-btn--warning plume-btn--xs"
								onClick={ handleSave }
							>
								{ __( 'Save', 'plume' ) }
							</button>
							<button
								type="button"
								className="plume-btn plume-btn--ghost plume-btn--xs"
								onClick={ () => {
									setInputText( '' );
									setActiveAnchor( null );
								} }
							>
								{ __( 'Discard', 'plume' ) }
							</button>
						</div>
					) }
				</div>
			</div>
		</div>
	);
}
