import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { CornerDownLeft, Loader2, Paperclip, FileText, X } from 'lucide-react';
import ContextPicker from './ContextPicker';

/**
 * Message composer with optional post-context attachment.
 *
 * Submits on Enter (without Shift) or Send button click. Shows a ContextPicker
 * popover when the attach button is clicked, and renders an attachment pill
 * while a post is attached.
 *
 * @param {Object}        props
 * @param {Function}      props.onSend       Called with the trimmed message string when submitted.
 * @param {boolean}       props.isLoading    Disables the input and shows a spinner while true.
 * @param {Object|null}   props.attachedPost Currently attached post object (`{ id, title }`) or null.
 * @param {Function}      props.onAttach     Called with the selected post object when context is chosen.
 * @param {Function}      props.onDetach     Called when the attachment pill dismiss button is clicked.
 * @return {ReactElement}
 */
export default function Composer( {
	onSend,
	isLoading,
	attachedPost,
	onAttach,
	onDetach,
} ) {
	const [ value, setValue ] = useState( '' );
	const [ showPicker, setShowPicker ] = useState( false );

	function handleKeyDown( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			submit();
		}
	}

	function submit() {
		const text = value.trim();
		if ( ! text || isLoading ) {
			return;
		}
		setValue( '' );
		onSend( text );
	}

	return (
		<div className="wpaim-composer">
			{ showPicker && (
				<ContextPicker
					onSelect={ ( post ) => {
						onAttach( post );
						setShowPicker( false );
					} }
					onClose={ () => setShowPicker( false ) }
				/>
			) }
			{ attachedPost && (
				<div className="wpaim-composer__attachment">
					<span className="wpaim-composer__attachment-pill">
						<FileText size={ 11 } strokeWidth={ 1.5 } />
						{ attachedPost.title }
						<button
							className="wpaim-composer__attachment-dismiss"
							onClick={ onDetach }
							title="Remove context"
							aria-label="Remove context"
							type="button"
						>
							<X size={ 10 } strokeWidth={ 2 } />
						</button>
					</span>
				</div>
			) }
			<div className="wpaim-composer__row">
				<textarea
					className="wpaim-composer__input"
					placeholder="Ask anything, or describe what you want to create…"
					value={ value }
					rows={ 1 }
					onChange={ ( e ) => setValue( e.target.value ) }
					onKeyDown={ handleKeyDown }
					disabled={ isLoading }
				/>
				<button
					className="wpaim-btn wpaim-btn--ghost wpaim-btn--icon"
					onClick={ () => setShowPicker( ( prev ) => ! prev ) }
					title="Attach post context"
					aria-label="Attach post context"
					type="button"
				>
					<Paperclip size={ 14 } strokeWidth={ 1.5 } />
				</button>
				<Button
					variant="primary"
					className="wpaim-btn--icon"
					onClick={ submit }
					disabled={ ! value.trim() || isLoading }
					label="Send (Enter)"
				>
					{ isLoading ? (
						<Loader2
							size={ 14 }
							strokeWidth={ 1.5 }
							className="wpaim-spinner"
						/>
					) : (
						<CornerDownLeft size={ 14 } strokeWidth={ 1.5 } />
					) }
				</Button>
			</div>
		</div>
	);
}
