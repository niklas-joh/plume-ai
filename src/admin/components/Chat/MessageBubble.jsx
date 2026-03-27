import { Cpu } from 'lucide-react';
import MarkdownContent from '../../../shared/MarkdownContent';

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
