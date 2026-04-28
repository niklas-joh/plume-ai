import { Cpu } from 'lucide-react';
import MarkdownContent from '../../../shared/MarkdownContent';

/**
 * Renders a single chat message bubble.
 *
 * AI messages are rendered as sanitised Markdown; user messages are plain text.
 * Error messages receive an additional error modifier class.
 *
 * @param {Object} props
 * @param {Object} props.message           Message object from the conversation history.
 * @param {string} props.message.role      Either 'user' or 'assistant'.
 * @param {string} props.message.content   Message text (Markdown for AI, plain text for user).
 * @param {string} [props.message.model]   Model slug displayed in the meta line for AI messages.
 * @param {number} [props.message.tokens]  Token count displayed in the meta line for AI messages.
 * @param {boolean} [props.message.isError] When true, applies the error modifier class.
 * @return {ReactElement}
 */
export default function MessageBubble( { message } ) {
	const isAI = message.role === 'assistant';

	return (
		<div
			className={ `wpaim-bubble wpaim-bubble--${ isAI ? 'ai' : 'user' }${
				message.isError ? ' wpaim-bubble--error' : ''
			}` }
		>
			<div className="wpaim-bubble__content">
				{ isAI ? (
					<MarkdownContent
						content={ message.content }
						className="wpaim-bubble__markdown"
					/>
				) : (
					<p>{ message.content }</p>
				) }
			</div>
			{ isAI && message.model && (
				<div className="wpaim-bubble__meta">
					<Cpu size={ 10 } strokeWidth={ 1.5 } />
					<span>{ message.model }</span>
					{ message.tokens && <span>{ message.tokens } tokens</span> }
				</div>
			) }
		</div>
	);
}
