import { useEffect, useRef } from '@wordpress/element';
import { Loader2 } from 'lucide-react';
import MessageBubble from './MessageBubble';

export default function MessageList( { messages, isLoading } ) {
	const bottomRef = useRef( null );

	useEffect( () => {
		bottomRef.current?.scrollIntoView( { behavior: 'smooth' } );
	}, [ messages, isLoading ] );

	return (
		<div className="wpaim-messages">
			{ messages.map( ( msg, i ) => (
				<MessageBubble key={ i } message={ msg } />
			) ) }
			{ isLoading && (
				<div className="wpaim-bubble wpaim-bubble--ai">
					<div className="wpaim-bubble__content">
						<Loader2
							size={ 14 }
							strokeWidth={ 1.5 }
							className="wpaim-spinner"
						/>
					</div>
				</div>
			) }
			<div ref={ bottomRef } />
		</div>
	);
}
