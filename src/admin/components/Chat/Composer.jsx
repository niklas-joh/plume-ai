import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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
 * The box takes focus when it appears and keeps it after sending, so a
 * conversation never needs a click back into the field. It stays editable
 * while a reply is on its way (only sending waits), grows with its text up to
 * the CSS max-height, and ignores the Enter that confirms an input-method
 * composition (Japanese, Chinese, Korean), which would otherwise send a
 * half-typed message.
 *
 * @param {Object}        props
 * @param {Function}      props.onSend           Called with the trimmed message string when submitted.
 * @param {boolean}       props.isLoading        Blocks sending and shows a spinner while true.
 * @param {Object|null}   props.attachedPost     Currently attached post object (`{ id, title, edit_link }`) or null.
 * @param {Function}      props.onAttach         Called with the selected post object when context is chosen.
 * @param {Function}      props.onDetach         Called when the attachment pill dismiss button is clicked.
 * @param {boolean}       [props.borderless]     When true, removes the top border (used in the centred launch view).
 * @param {boolean}       [props.forcePickerOpen] When flipped to true, opens the context picker immediately.
 * @param {Function}      [props.onPickerClose]  Called when the picker is dismissed (allows parent to reset the force flag).
 * @return {ReactElement}
 */
export default function Composer( {
	onSend,
	isLoading,
	attachedPost,
	onAttach,
	onDetach,
	borderless,
	forcePickerOpen,
	onPickerClose,
} ) {
	const [ value, setValue ] = useState( '' );
	const [ showPicker, setShowPicker ] = useState( false );
	const inputRef = useRef( null );

	// Focus on mount rather than with autoFocus: the composer remounts when the
	// launch view gives way to the conversation, and a ref also lets submit()
	// put the cursor back after sending.
	useEffect( () => {
		inputRef.current?.focus();
	}, [] );

	// Grow with the text. Resetting to auto first lets the box shrink again when
	// text is deleted; max-height in CSS caps it and turns on scrolling.
	useEffect( () => {
		const el = inputRef.current;
		if ( ! el ) {
			return;
		}
		el.style.height = 'auto';
		el.style.height = `${ el.scrollHeight }px`;
	}, [ value ] );

	// Allow a parent to imperatively open the picker (e.g. when a quick action
	// fires but no post is yet attached).
	useEffect( () => {
		if ( forcePickerOpen ) {
			setShowPicker( true );
		}
	}, [ forcePickerOpen ] );

	function handleKeyDown( e ) {
		// keyCode 229: Safari fires the composition-confirming Enter after
		// compositionend, so isComposing alone does not catch it.
		if ( e.nativeEvent?.isComposing || e.keyCode === 229 ) {
			return;
		}
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
		inputRef.current?.focus();
	}

	return (
		<div
			className={ `plume-composer${
				borderless ? ' plume-composer--borderless' : ''
			}` }
		>
			{ showPicker && (
				<ContextPicker
					onSelect={ ( post ) => {
						onAttach( post );
						setShowPicker( false );
					} }
					onClose={ () => {
						setShowPicker( false );
						onPickerClose?.();
					} }
				/>
			) }
			{ attachedPost && (
				<div className="plume-composer__attachment">
					<span className="plume-composer__attachment-pill">
						{ attachedPost.edit_link && (
							<a
								href={ attachedPost.edit_link }
								target="_blank"
								rel="noopener noreferrer"
								className="plume-composer__attachment-link"
								aria-label={ sprintf(
									/* translators: %s: post title. */
									__( 'Edit "%s" (opens in new tab)', 'plume' ),
									attachedPost.title
								) }
							/>
						) }
						<FileText size={ 11 } strokeWidth={ 1.5 } />
						{ attachedPost.title }
						<button
							className="plume-composer__attachment-dismiss"
							onClick={ onDetach }
							title={ __( 'Remove context', 'plume' ) }
							aria-label={ __( 'Remove context', 'plume' ) }
							type="button"
						>
							<X size={ 10 } strokeWidth={ 2 } />
						</button>
					</span>
				</div>
			) }
			<div className="plume-composer__row">
				<textarea
					ref={ inputRef }
					className="plume-composer__input"
					placeholder={ __(
						'Ask anything, or describe what you want to create…',
						'plume'
					) }
					aria-label={ __( 'Message', 'plume' ) }
					value={ value }
					rows={ 1 }
					onChange={ ( e ) => setValue( e.target.value ) }
					onKeyDown={ handleKeyDown }
				/>
				<button
					className="plume-btn plume-btn--ghost plume-btn--icon"
					onClick={ () => setShowPicker( ( prev ) => ! prev ) }
					title={ __( 'Attach post context', 'plume' ) }
					aria-label={ __( 'Attach post context', 'plume' ) }
					type="button"
				>
					<Paperclip size={ 14 } strokeWidth={ 1.5 } />
				</button>
				<Button
					variant="primary"
					className="plume-btn--icon"
					onClick={ submit }
					disabled={ ! value.trim() || isLoading }
					label={ __( 'Send (Enter)', 'plume' ) }
				>
					{ isLoading ? (
						<Loader2
							size={ 14 }
							strokeWidth={ 1.5 }
							className="plume-spinner"
						/>
					) : (
						<CornerDownLeft size={ 14 } strokeWidth={ 1.5 } />
					) }
				</Button>
			</div>
		</div>
	);
}
