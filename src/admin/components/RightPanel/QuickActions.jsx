import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { QUICK_ACTIONS } from '../Chat/actions';

/**
 * Pre-defined prompt shortcuts displayed in the right panel.
 *
 * Every action is available to every tier — credit exhaustion is enforced
 * by the Worker, not a tier-based action split. Actions with
 * requiresPost=true call onRequestAttach instead of onAction when no post
 * is currently attached, so the user is prompted to select one first.
 *
 * @param {Object}      props
 * @param {Function}    props.onAction        Called with (prompt) when an action fires without a post requirement.
 * @param {Object|null} props.attachedPost    Currently attached post, or null.
 * @param {Function}    props.onRequestAttach Called with the pending prompt when a post is required but missing.
 * @return {ReactElement}
 */
export default function QuickActions( {
	onAction,
	attachedPost,
	onRequestAttach,
} ) {
	function handleClick( action ) {
		if ( action.requiresPost && ! attachedPost ) {
			onRequestAttach( action.prompt );
		} else {
			onAction( action.prompt );
		}
	}

	return (
		<div className="plume-panel-section">
			<div className="plume-panel-label">
				{ __( 'Quick actions', 'plume' ) }
			</div>
			<div className="plume-quick-actions">
				{ QUICK_ACTIONS.map( ( action ) => (
					<Button
						key={ action.id }
						variant="tertiary"
						className="plume-quick-action"
						onClick={ () => handleClick( action ) }
					>
						<action.icon size={ 12 } strokeWidth={ 1.5 } />
						<span>{ action.label }</span>
					</Button>
				) ) }
			</div>
		</div>
	);
}
