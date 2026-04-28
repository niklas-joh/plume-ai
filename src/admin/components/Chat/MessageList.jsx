import { useEffect, useRef } from '@wordpress/element';
import { Loader2 } from 'lucide-react';
import MessageBubble from './MessageBubble';

/**
 * Scrollable list of message bubbles with a loading indicator.
 *
 * Auto-scrolls to the bottom whenever `messages` or `isLoading` changes.
 *
 * @param {Object}  props
 * @param {Array}   props.messages   Array of message objects passed to MessageBubble.
 * @param {boolean} props.isLoading  When true, appends a spinner bubble at the bottom.
 * @return {ReactElement}
 */
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
