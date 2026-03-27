export default function ConversationHistory( {
	conversations,
	activeId,
	onSelect,
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
				<button
					key={ conv.id }
					className={ `wpaim-conv-item ${
						conv.id === activeId ? 'is-active' : ''
					}` }
					onClick={ () => onSelect( conv.id ) }
				>
					<span className="wpaim-conv-item__title">
						{ conv.title || 'Untitled' }
					</span>
					<span className="wpaim-conv-item__date">
						{ new Date( conv.updated_at ).toLocaleDateString() }
					</span>
				</button>
			) ) }
		</nav>
	);
}
