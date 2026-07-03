import { useState, useRef, useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MessageSquare } from 'lucide-react';
import CommentThread from './CommentThread';
import { htmlToText } from '../../utils/htmlToText';

/**
 * Flatten DiffBlocks into a single list of paragraph rows so that every
 * paragraph — unchanged, removed, added, or an inline word-diff — is an
 * independently selectable, commentable unit rendered with uniform typography.
 * Change is conveyed only by the red (removed) / green (added) markers, not by
 * any per-block background or border.
 *
 * @param {Array} blocks computeDiff output.
 * @return {Array<{id: string, kind: 'unchanged'|'removed'|'added'|'inline', html: string}>}
 */
function buildRows( blocks ) {
	const rows = [];
	blocks.forEach( ( block ) => {
		block.unchanged.forEach( ( para, i ) => {
			rows.push( {
				id: `${ block.id }-u${ i }`,
				kind: 'unchanged',
				html: para,
			} );
		} );
		if ( block.inlineHtml ) {
			rows.push( {
				id: block.id,
				kind: 'inline',
				html: block.inlineHtml,
			} );
			return;
		}
		if ( block.removedText ) {
			rows.push( {
				id: `${ block.id }-r`,
				kind: 'removed',
				html: block.removedText,
			} );
		}
		if ( block.addedText ) {
			rows.push( { id: block.id, kind: 'added', html: block.addedText } );
		}
	} );
	return rows;
}

/**
 * Scrollable diff body with text-selection tooltip and sticky legend bar.
 *
 * Renders every paragraph — unchanged, removed (strikethrough red), added
 * (green), or an inline word-diff — with uniform typography via
 * `dangerouslySetInnerHTML`, so headings, lists, and other block elements keep
 * their structure and change is signalled only by the red/green markers.
 *
 * Any paragraph can be drag-selected to attach a comment via a floating
 * tooltip; each paragraph is an independently commentable unit.
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
	 * attribute (set on every paragraph row, so any text is commentable).
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
				{ buildRows( blocks ).map( ( row ) => {
					const rowComments = commentsForBlock( row.id );
					const kindClass = {
						unchanged: 'plume-diff-block__unchanged',
						removed: 'plume-diff-block__removed',
						added: 'plume-diff-added',
						inline: 'plume-diff-block__inline',
					}[ row.kind ];
					const kindLabel = {
						unchanged: __( 'Unchanged', 'plume' ),
						removed: __( 'Removed text', 'plume' ),
						added: __( 'Proposed text', 'plume' ),
						inline: __( 'Changed text', 'plume' ),
					}[ row.kind ];

					return (
						<div key={ row.id } className="plume-diff-row">
							{ /* eslint-disable-next-line react/no-danger */ }
							<div
								className={ kindClass }
								data-block-id={ row.id }
								aria-label={
									row.kind === 'unchanged'
										? kindLabel
										: `${ kindLabel }: ${ htmlToText(
												row.html
										  ) }`
								}
								dangerouslySetInnerHTML={ { __html: row.html } }
							/>
							{ rowComments.length > 0 && (
								<span
									className="plume-diff-badge"
									aria-hidden="true"
								>
									<MessageSquare size={ 10 } />
									{ rowComments.length }
								</span>
							) }
							<CommentThread
								diffBlockId={ row.id }
								comments={ rowComments }
								onSave={ onSaveComment }
								onDelete={ onDeleteComment }
								onUnsavedChange={ onUnsavedChange }
								pendingAnchor={
									pendingAnchors[ row.id ] ?? null
								}
								onAnchorConsumed={ () =>
									setPendingAnchors( ( prev ) => {
										const next = { ...prev };
										delete next[ row.id ];
										return next;
									} )
								}
							/>
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
