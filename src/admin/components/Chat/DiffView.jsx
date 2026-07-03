import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MessageSquare } from 'lucide-react';
import CommentThread from './CommentThread';
import { htmlToText } from '../../utils/htmlToText';

/**
 * Scrollable diff body with text-selection tooltip and sticky legend bar.
 *
 * Renders each DiffBlock as unchanged (dimmed), removed (strikethrough red),
 * and added (green, selectable) paragraphs using `dangerouslySetInnerHTML`
 * so that headings, lists, and other block-level elements are rendered
 * semantically rather than as flat text.
 *
 * Users can drag-select text inside any annotatable block (removed or added)
 * to trigger a comment via a floating tooltip.
 *
 * @param {Object}   props
 * @param {Array}    props.blocks            DiffBlock[] from computeDiff.
 * @param {Array}    props.comments          Flat Comment[] array for all blocks.
 * @param {Function} props.onAddComment      Called with `(diffBlockId, selectedText)` when user confirms a selection.
 * @param {Function} props.onSaveComment     Forwarded to CommentThread.onSave.
 * @param {Function} props.onDeleteComment   Forwarded to CommentThread.onDelete.
 * @param {Function} props.onUnsavedChange   Forwarded to CommentThread.onUnsavedChange.
 * @param {string}   props.drawerState       Current drawer state for legend rendering.
 * @param {ReactNode} [props.header]         Optional node rendered at the top of the scroll body (e.g. the plan summary), so it scrolls with the diff.
 * @return {ReactElement}
 */
export default function DiffView( {
	blocks,
	comments,
	onAddComment,
	onSaveComment,
	onDeleteComment,
	onUnsavedChange,
	drawerState,
	header = null,
} ) {
	const [ tooltip, setTooltip ] = useState( null ); // { x, y, diffBlockId, selectedText }
	const [ pendingAnchors, setPendingAnchors ] = useState( {} ); // diffBlockId -> selectedText
	const bodyRef = useRef( null );

	const commentsForBlock = useCallback(
		( blockId ) => comments.filter( ( c ) => c.diffBlockId === blockId ),
		[ comments ]
	);

	/**
	 * Walks up from a node looking for any element with a `data-block-id`
	 * attribute (set on both removed and added wrappers).
	 *
	 * @param {Node} node
	 * @return {Element|null}
	 */
	function findBlockAncestor( node ) {
		let el = node.nodeType === 3 ? node.parentElement : node;
		while ( el && el !== bodyRef.current ) {
			if ( el.dataset?.blockId ) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	// Reads the live selection and positions (or dismisses) the "Add comment"
	// tooltip. Shared by pointerup (immediate, desktop) and a debounced
	// selectionchange listener (the reliable signal on iOS Safari, which does
	// not fire pointerup after a long-press/handle selection).
	const updateTooltipFromSelection = useCallback( () => {
		if ( ! bodyRef.current ) {
			return;
		}
		const sel = bodyRef.current.ownerDocument?.defaultView?.getSelection();
		if ( ! sel || sel.isCollapsed || ! sel.toString().trim() ) {
			setTooltip( null );
			return;
		}

		const blockEl = findBlockAncestor( sel.anchorNode );
		if ( ! blockEl || ! bodyRef.current.contains( blockEl ) ) {
			// Selection not in a commentable block — dismiss tooltip without clearing selection.
			setTooltip( null );
			return;
		}

		const diffBlockId = blockEl.dataset.blockId;
		const selectedText = sel.toString().trim();
		const range = sel.getRangeAt( 0 );
		const rect = range.getBoundingClientRect();
		const bodyRect = bodyRef.current.getBoundingClientRect();

		setTooltip( {
			x: rect.left - bodyRect.left + rect.width / 2,
			y: rect.bottom - bodyRect.top + 8,
			diffBlockId,
			selectedText,
		} );
	}, [] );

	// Touch-friendly selection: iOS fires `selectionchange` while dragging the
	// selection handles but not pointerup, so debounce it and re-evaluate once
	// the selection settles.
	useEffect( () => {
		const doc = bodyRef.current?.ownerDocument ?? document;
		let timer = null;
		const onSelectionChange = () => {
			if ( timer ) {
				clearTimeout( timer );
			}
			timer = setTimeout( updateTooltipFromSelection, 250 );
		};
		doc.addEventListener( 'selectionchange', onSelectionChange );
		return () => {
			if ( timer ) {
				clearTimeout( timer );
			}
			doc.removeEventListener( 'selectionchange', onSelectionChange );
		};
	}, [ updateTooltipFromSelection ] );

	function handleTooltipClick() {
		if ( ! tooltip ) {
			return;
		}
		const { diffBlockId, selectedText } = tooltip;
		setPendingAnchors( ( prev ) => ( {
			...prev,
			[ diffBlockId ]: selectedText,
		} ) );
		onAddComment( diffBlockId, selectedText );
		// Don't clear selection here — it clears naturally when the textarea receives focus.
		setTooltip( null );
	}

	function handleBodyClick( e ) {
		// Dismiss tooltip on click outside it — no selection clearing so the user can re-select.
		if ( tooltip && ! e.target.closest( '.plume-add-comment-tooltip' ) ) {
			setTooltip( null );
		}
	}

	const hasComments = comments.some( ( c ) => c.diffBlockId );

	return (
		<div className="plume-diff-view">
			{ /* Scrollable diff body */ }
			{ /* Text-selection surface: drag-select to comment has no keyboard
			   analogue, and the click handler only dismisses the selection
			   tooltip. */ }
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events */ }
			<div
				ref={ bodyRef }
				className="plume-diff-view__body"
				onPointerUp={ updateTooltipFromSelection }
				onClick={ handleBodyClick }
				style={ { position: 'relative' } }
			>
				{ header }
				{ blocks.map( ( block ) => {
					const blockComments = commentsForBlock( block.id );
					const isAnnotated = blockComments.length > 0;

					return (
						<div key={ block.id } className="plume-diff-block">
							{ block.unchanged.map( ( para, idx ) => (
								// eslint-disable-next-line react/no-danger
								<div
									key={ idx }
									className="plume-diff-block__unchanged"
									aria-label={ __( 'Unchanged', 'plume' ) }
									dangerouslySetInnerHTML={ { __html: para } }
								/>
							) ) }

							{ /* A modified block with a word-level inline diff hides the
							   separate red "removed" block — the deletions are shown
							   inline instead. */ }
							{ ! block.inlineHtml && block.removedText && (
								// eslint-disable-next-line react/no-danger
								<div
									className="plume-diff-block__removed"
									data-block-id={ block.id }
									aria-label={ `${ __(
										'Removed text',
										'plume'
									) }: ${ htmlToText( block.removedText ) }` }
									dangerouslySetInnerHTML={ {
										__html: block.removedText,
									} }
								/>
							) }

							{ ( block.inlineHtml || block.addedText ) && (
								<div className="plume-diff-block__added-wrapper">
									{ /* eslint-disable-next-line react/no-danger */ }
									<div
										className={
											block.inlineHtml
												? `plume-diff-block__inline${
														isAnnotated
															? ' plume-diff-block__inline--annotated'
															: ''
												  }`
												: `plume-diff-added${
														isAnnotated
															? ' plume-diff-added--annotated'
															: ''
												  }`
										}
										data-block-id={ block.id }
										aria-label={
											block.inlineHtml
												? `${ __(
														'Changed text',
														'plume'
												  ) }: ${ htmlToText(
														block.inlineHtml
												  ) }`
												: `${ __(
														'Proposed text',
														'plume'
												  ) }: ${ htmlToText(
														block.addedText
												  ) }`
										}
										dangerouslySetInnerHTML={ {
											__html:
												block.inlineHtml ??
												block.addedText,
										} }
									/>
									{ isAnnotated && (
										<span
											className="plume-diff-badge"
											aria-hidden="true"
										>
											<MessageSquare size={ 10 } />
											{ blockComments.length }
										</span>
									) }
									<CommentThread
										diffBlockId={ block.id }
										comments={ blockComments }
										onSave={ onSaveComment }
										onDelete={ onDeleteComment }
										onUnsavedChange={ onUnsavedChange }
										pendingAnchor={
											pendingAnchors[ block.id ] ?? null
										}
										onAnchorConsumed={ () =>
											setPendingAnchors( ( prev ) => {
												const next = { ...prev };
												delete next[ block.id ];
												return next;
											} )
										}
									/>
								</div>
							) }
						</div>
					);
				} ) }

				{ tooltip && (
					<button
						type="button"
						className="plume-add-comment-tooltip"
						style={ { left: tooltip.x, top: tooltip.y } }
						onClick={ handleTooltipClick }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' || e.key === ' ' ) {
								e.preventDefault();
								handleTooltipClick();
							}
						} }
					>
						{ __( 'Add comment', 'plume' ) }
					</button>
				) }
			</div>

			{ /* Sticky legend bar */ }
			<div className="plume-diff-view__legend">
				<span className="plume-diff-legend__item plume-diff-legend__item--removed">
					{ __( 'Removed', 'plume' ) }
				</span>
				<span className="plume-diff-legend__item plume-diff-legend__item--added">
					{ __( 'Added', 'plume' ) }
				</span>
				<span className="plume-diff-legend__item plume-diff-legend__item--unchanged">
					{ __( 'Unchanged', 'plume' ) }
				</span>
				{ ( drawerState === 'commenting' || hasComments ) && (
					<span className="plume-diff-legend__item plume-diff-legend__item--commented">
						<MessageSquare size={ 10 } />
						{ __( 'Commented', 'plume' ) }
					</span>
				) }
			</div>
		</div>
	);
}
