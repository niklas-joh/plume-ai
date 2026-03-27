import { useState, useRef, useEffect } from '@wordpress/element';
import { Send, Loader2 } from 'lucide-react';
import apiFetch from '@wordpress/api-fetch';
import MarkdownContent from '../../shared/MarkdownContent';

export default function MiniChat( { postId } ) {
	const [ messages, setMessages ] = useState( [] );
	const [ input, setInput ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ convId, setConvId ] = useState( null );
	const endRef = useRef( null );

	useEffect( () => {
		endRef.current?.scrollIntoView( { behavior: 'smooth' } );
	}, [ messages ] );

	async function send() {
		if ( ! input.trim() || isLoading ) {
			return;
		}
		const text = input.trim();
		setInput( '' );
		setMessages( ( prev ) => [ ...prev, { role: 'user', content: text } ] );
		setIsLoading( true );

		try {
			// Create conversation on first message.
			let cid = convId;
			if ( ! cid ) {
				const conv = await apiFetch( {
					path: '/wp-ai-mind/v1/conversations',
					method: 'POST',
					data: { title: text.slice( 0, 60 ), post_id: postId },
				} );
				cid = conv.id;
				setConvId( cid ); // capture new ID — stale closure fix
			}
			const res = await apiFetch( {
				path: `/wp-ai-mind/v1/conversations/${ cid }/messages`,
				method: 'POST',
				data: { content: text },
			} );
			setMessages( ( prev ) => [
				...prev,
				{ role: 'assistant', content: res.content },
			] );
		} finally {
			setIsLoading( false );
		}
	}

	return (
		<div className="wpaim-editor-mini-chat">
			<div className="wpaim-editor-mini-chat__messages">
				{ messages.length === 0 && (
					<p
						style={ {
							color: 'var(--color-text-muted)',
							fontSize: 'var(--text-sm)',
							textAlign: 'center',
							padding: 'var(--space-4)',
						} }
					>
						Ask anything about this post…
					</p>
				) }
				{ messages.map( ( m, i ) => (
					<div
						key={ i }
						className={ `wpaim-editor-mini-chat__bubble wpaim-editor-mini-chat__bubble--${
							m.role === 'user' ? 'user' : 'ai'
						}` }
					>
						{ m.role === 'assistant' ? (
							<MarkdownContent content={ m.content } />
						) : (
							m.content
						) }
					</div>
				) ) }
				{ isLoading && (
					<div className="wpaim-editor-mini-chat__bubble wpaim-editor-mini-chat__bubble--ai">
						<Loader2 size={ 14 } className="wpaim-spin" />
					</div>
				) }
				<div ref={ endRef } />
			</div>
			<div className="wpaim-editor-mini-chat__composer">
				<input
					className="wpaim-editor-mini-chat__input"
					value={ input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ ( e ) =>
						e.key === 'Enter' &&
						! e.shiftKey &&
						( e.preventDefault(), send() )
					}
					placeholder="Ask AI…"
					disabled={ isLoading }
				/>
				<button
					className="wpaim-editor-mini-chat__send"
					onClick={ send }
					disabled={ isLoading || ! input.trim() }
				>
					{ isLoading ? (
						<Loader2 size={ 14 } className="wpaim-spin" />
					) : (
						<Send size={ 14 } />
					) }
				</button>
			</div>
		</div>
	);
}
