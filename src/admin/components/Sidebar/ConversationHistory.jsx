import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Trash2, Check, X } from 'lucide-react';

/**
 * Sidebar list of past conversations with inline delete confirmation.
 *
 * Shows an empty state when there are no conversations. Delete uses a
 * two-step confirm flow (click → confirm/cancel) to prevent accidental deletion.
 * Delete errors are shown inline beneath the affected conversation row.
 *
 * @param {Object}  props
 * @param {Array}   props.conversations       Array of conversation objects: `{ id, title, updated_at }`.
 * @param {number|null} props.activeId        ID of the currently open conversation, or null.
 * @param {Function}    props.onSelect        Called with the conversation ID when a row is clicked.
 * @param {Function}    props.onDelete        Called with the conversation ID to trigger deletion.
 * @param {Set}         [props.deletingIds]   Set of conversation IDs currently being deleted (shows disabled state).
 * @param {Object}      [props.deleteErrors]  Map of conversation ID → error string for failed deletes.
 * @return {ReactElement}
 */
export default function ConversationHistory( {
	conversations,
	activeId,
	onSelect,
	onDelete,
	deletingIds = new Set(),
	deleteErrors = {},
} ) {
	const [ confirmingId, setConfirmingId ] = useState( null );

	if ( conversations.length === 0 ) {
		return (
			<div className="plume-sidebar__empty">
				<p>{ __( 'No conversations yet.', 'plume' ) }</p>
			</div>
		);
	}
	return (
		<nav className="plume-conv-list">
			{ conversations.map( ( conv ) => (
				<div
					key={ conv.id }
					className={ `plume-conv-item ${
						conv.id === activeId ? 'is-active' : ''
					}` }
				>
					<button
						className="plume-conv-item__body"
						onClick={ () => onSelect( conv.id ) }
						type="button"
						title={ conv.title || __( 'Untitled', 'plume' ) }
					>
						<span className="plume-conv-item__title">
							{ conv.title || __( 'Untitled', 'plume' ) }
						</span>
						<span className="plume-conv-item__date">
							{ conv.updated_at
								? new Date(
										conv.updated_at
									).toLocaleDateString( navigator.language, {
										day: '2-digit',
										month: '2-digit',
										year: 'numeric',
									} )
								: '—' }
						</span>
					</button>
					{ deleteErrors[ conv.id ] && (
						<p className="plume-conv-item__error">
							{ deleteErrors[ conv.id ] }
						</p>
					) }
					{ confirmingId === conv.id ? (
						<span className="plume-conv-item__confirm">
							<button
								className="plume-conv-item__confirm-yes plume-btn plume-btn--ghost"
								onClick={ () => {
									setConfirmingId( null );
									onDelete( conv.id );
								} }
								type="button"
								title={ __( 'Confirm delete', 'plume' ) }
								aria-label={ sprintf(
									/* translators: %s: conversation title. */
									__(
										'Confirm delete conversation: %s',
										'plume'
									),
									conv.title || __( 'Untitled', 'plume' )
								) }
							>
								<Check size={ 12 } strokeWidth={ 1.5 } />
							</button>
							<button
								className="plume-conv-item__confirm-no plume-btn plume-btn--ghost"
								onClick={ () => setConfirmingId( null ) }
								type="button"
								title={ __( 'Cancel delete', 'plume' ) }
								aria-label={ __( 'Cancel delete', 'plume' ) }
							>
								<X size={ 12 } strokeWidth={ 1.5 } />
							</button>
						</span>
					) : (
						<button
							className="plume-conv-item__delete plume-btn plume-btn--ghost"
							onClick={ () => setConfirmingId( conv.id ) }
							type="button"
							title={ __( 'Delete conversation', 'plume' ) }
							aria-label={ sprintf(
								/* translators: %s: conversation title. */
								__( 'Delete conversation: %s', 'plume' ),
								conv.title || __( 'Untitled', 'plume' )
							) }
							disabled={ deletingIds.has( conv.id ) }
						>
							<Trash2 size={ 12 } strokeWidth={ 1.5 } />
						</button>
					) }
				</div>
			) ) }
		</nav>
	);
}
