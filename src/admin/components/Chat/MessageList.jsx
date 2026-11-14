import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Loader2 } from 'lucide-react';
import MessageBubble from './MessageBubble';

// A turn can take up to a minute (several tool steps, each a provider call),
// so the waiting text changes as the wait grows. Times are from the start of
// the wait, in milliseconds. Purely time-based: the server reports no progress.
const WAITING_STAGES = [
	{ after: 0, text: __( 'Thinking…', 'plume' ) },
	{ after: 8000, text: __( 'Still working on it…', 'plume' ) },
	{
		after: 25000,
		text: __(
			'Longer answers and post edits can take up to a minute.',
			'plume'
		),
	},
];

/**
 * Text describing the current stage of a wait, updated as time passes.
 *
 * @param {boolean} isWaiting Whether a reply is on its way.
 * @return {string} The current stage's text, or an empty string when not waiting.
 */
function useWaitingText( isWaiting ) {
	const [ stage, setStage ] = useState( 0 );

	useEffect( () => {
		setStage( 0 );
		if ( ! isWaiting ) {
			return;
		}
		const timers = WAITING_STAGES.slice( 1 ).map( ( { after }, i ) =>
			setTimeout( () => setStage( i + 1 ), after )
		);
		return () => timers.forEach( clearTimeout );
	}, [ isWaiting ] );

	return isWaiting ? WAITING_STAGES[ stage ].text : '';
}

/**
 * Scrollable list of message bubbles with a waiting indicator.
 *
 * Auto-scrolls to the bottom whenever `messages` or `isLoading` changes.
 *
 * While a reply is on its way, the indicator says what is happening and
 * reassures the user as the wait grows. The text lives in one status region
 * that is always mounted (empty when idle): screen readers announce changes
 * to an existing live region, but often miss one inserted with its text.
 *
 * @param {Object}  props
 * @param {Array}   props.messages   Array of message objects passed to MessageBubble.
 * @param {boolean} props.isLoading  When true, shows the waiting indicator at the bottom.
 * @param {Function} [props.onRetry] Called with a failed message to send it again; offered on the newest message only.
 * @return {ReactElement}
 */
export default function MessageList( { messages, isLoading, onRetry } ) {
	const bottomRef = useRef( null );
	const waitingText = useWaitingText( isLoading );

	useEffect( () => {
		bottomRef.current?.scrollIntoView( { behavior: 'smooth' } );
	}, [ messages, isLoading ] );

	return (
		<div className="plume-messages">
			{ messages.map( ( msg, i ) => (
				<MessageBubble
					key={ i }
					message={ msg }
					isLoading={ isLoading }
					// Only the newest failure can be retried: retrying an older one
					// would answer a question out of order.
					onRetry={
						onRetry && msg.retry && i === messages.length - 1
							? () => onRetry( msg )
							: undefined
					}
				/>
			) ) }
			<div
				className={ `plume-bubble plume-bubble--ai plume-waiting${
					isLoading ? '' : ' is-idle'
				}` }
			>
				{ isLoading && (
					<div className="plume-bubble__content">
						<Loader2
							size={ 14 }
							strokeWidth={ 1.5 }
							className="plume-spinner"
							aria-hidden="true"
						/>
					</div>
				) }
				<span
					className="plume-waiting__text"
					role="status"
					aria-live="polite"
				>
					{ waitingText }
				</span>
			</div>
			<div ref={ bottomRef } />
		</div>
	);
}
