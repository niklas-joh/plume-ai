/* global navigator */
import { useState } from '@wordpress/element';
import { Trash2, Check, X } from 'lucide-react';

/**
 * Sidebar list of past conversations with inline delete confirmation.
 *
 * Shows an empty state when there are no conversations. Delete uses a
 * two-step confirm flow (click → confirm/cancel) to prevent accidental deletion.
 *
 * @param {Object}  props
 * @param {Array}   props.conversations       Array of conversation objects: `{ id, title, updated_at }`.
 * @param {number|null} props.activeId        ID of the currently open conversation, or null.
 * @param {Function}    props.onSelect        Called with the conversation ID when a row is clicked.
 * @param {Function}    props.onDelete        Called with the conversation ID to trigger deletion.
 * @param {Set}         [props.deletingIds]   Set of conversation IDs currently being deleted (shows disabled state).
 * @return {ReactElement}
 */
export default function ConversationHistory( {
	conversations,
	activeId,
	onSelect,
	onDelete,
	deletingIds = new Set(),
} ) {
	const [ confirmingId, setConfirmingId ] = useState( null );

	if ( conversations.length === 0 ) {
		return (
			<div className="wpaim-sidebar__empty">
				<p>No conversations yet.</p>
			</div>
		);
	}
	return (
		<nav className="wpaim-conv-list">
			{ conversations.map( ( conv ) => (
				<div
					key={ conv.id }
					className={ `wpaim-conv-item ${
						conv.id === activeId ? 'is-active' : ''
					}` }
				>
					<button
						className="wpaim-conv-item__body"
						onClick={ () => onSelect( conv.id ) }
						type="button"
					>
						<span className="wpaim-conv-item__title">
							{ conv.title || 'Untitled' }
						</span>
						<span className="wpaim-conv-item__date">
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
					{ confirmingId === conv.id ? (
						<span className="wpaim-conv-item__confirm">
							<button
								className="wpaim-conv-item__confirm-yes wpaim-btn wpaim-btn--ghost"
								onClick={ () => {
									setConfirmingId( null );
									onDelete( conv.id );
								} }
								type="button"
								title="Confirm delete"
								aria-label={ `Confirm delete conversation: ${
									conv.title || 'Untitled'
								}` }
							>
								<Check size={ 12 } strokeWidth={ 1.5 } />
							</button>
							<button
								className="wpaim-conv-item__confirm-no wpaim-btn wpaim-btn--ghost"
								onClick={ () => setConfirmingId( null ) }
								type="button"
								title="Cancel delete"
								aria-label="Cancel delete"
							>
								<X size={ 12 } strokeWidth={ 1.5 } />
							</button>
						</span>
					) : (
						<button
							className="wpaim-conv-item__delete wpaim-btn wpaim-btn--ghost"
							onClick={ () => setConfirmingId( conv.id ) }
							type="button"
							title="Delete conversation"
							aria-label={ `Delete conversation: ${
								conv.title || 'Untitled'
							}` }
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
