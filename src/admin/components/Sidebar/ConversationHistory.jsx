import { Trash2 } from 'lucide-react';

export default function ConversationHistory( {
	conversations,
	activeId,
	onSelect,
	onDelete,
} ) {
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
							{ new Date( conv.updated_at ).toLocaleDateString() }
						</span>
					</button>
					<button
						className="wpaim-conv-item__delete wpaim-btn wpaim-btn--ghost"
						onClick={ () => onDelete( conv.id ) }
						type="button"
						title="Delete conversation"
						aria-label={ `Delete conversation: ${
							conv.title || 'Untitled'
						}` }
					>
						<Trash2 size={ 12 } strokeWidth={ 1.5 } />
					</button>
				</div>
			) ) }
		</nav>
	);
}
