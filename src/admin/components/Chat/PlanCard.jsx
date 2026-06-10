import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { FileText, Pencil, X, ExternalLink, Loader2 } from 'lucide-react';

const STATUS_LABELS = {
	draft: __( 'Draft', 'stilus' ),
	publish: __( 'Published', 'stilus' ),
	pending: __( 'Pending review', 'stilus' ),
};

/**
 * Inline approval card rendered below an AI message when the model proposes a post plan.
 *
 * Displays the plan title, outline (or changes for updates), and post status.
 * The user can create/update the post, edit the plan details before confirming, or dismiss.
 *
 * @param {Object}   props
 * @param {Object}   props.plan         Pending plan from the REST response.
 * @param {string}   props.plan.id        Plan identifier (used to call the execute endpoint).
 * @param {string}   props.plan.plan_type 'create' or 'update'.
 * @param {string}   [props.plan.title]       Post title (create plans).
 * @param {string}   [props.plan.outline]     Brief outline shown on the card (create plans).
 * @param {string}   [props.plan.content]     Full post body to create on approval (create plans).
 * @param {number}   [props.plan.post_id]     Source post ID (update plans).
 * @param {string}   [props.plan.changes]     Human-readable change summary shown on the card (update plans).
 * @param {string}   [props.plan.new_content] Full updated post content to apply (update plans).
 * @param {string}   [props.plan.new_title]   Updated post title, if changing (update plans).
 * @param {string}   [props.plan.post_status] Publication status.
 * @param {string}   [props.plan.post_type]   Post type.
 * @param {Function} props.onDismiss     Called when the user dismisses the card (no server call).
 * @return {ReactElement}
 *
 * @example
 * <PlanCard plan={ pending_plan } onDismiss={ () => clearPlan() } />
 */
export default function PlanCard( { plan, onDismiss } ) {
	const isUpdate = plan.plan_type === 'update';

	const [ isEditing, setIsEditing ] = useState( false );
	const [ editTitle, setEditTitle ] = useState(
		isUpdate ? plan.new_title ?? '' : plan.title ?? ''
	);
	const [ editContent, setEditContent ] = useState(
		isUpdate ? plan.new_content ?? '' : plan.content ?? plan.outline ?? ''
	);
	const [ editStatus, setEditStatus ] = useState(
		plan.post_status || 'draft'
	);

	const [ isExecuting, setIsExecuting ] = useState( false );
	const [ editUrl, setEditUrl ] = useState( null );
	const [ error, setError ] = useState( null );

	async function handleConfirm() {
		setIsExecuting( true );
		setError( null );
		try {
			const body = isUpdate
				? {
						new_content: editContent,
						new_title: editTitle !== '' ? editTitle : undefined,
						status: editStatus,
				  }
				: {
						title: editTitle,
						content: editContent,
						status: editStatus,
				  };

			const result = await apiFetch( {
				path: `/stilus/v1/plans/${ plan.id }/execute`,
				method: 'POST',
				data: body,
			} );
			setEditUrl( result.edit_url );
		} catch ( err ) {
			setError(
				err?.message ??
					__( 'Something went wrong. Please try again.', 'stilus' )
			);
		} finally {
			setIsExecuting( false );
		}
	}

	const confirmLabel = isUpdate
		? __( 'Apply update', 'stilus' )
		: __( 'Create post', 'stilus' );

	if ( editUrl ) {
		return (
			<div className="wpaim-plan-card wpaim-plan-card--done">
				<span className="wpaim-plan-card__done-text">
					{ isUpdate
						? __( 'Post updated.', 'stilus' )
						: __( 'Post created.', 'stilus' ) }
				</span>
				<a
					href={ editUrl }
					target="_blank"
					rel="noreferrer"
					className="wpaim-btn wpaim-btn--ghost wpaim-btn--sm"
				>
					{ __( 'Edit post', 'stilus' ) }
					<ExternalLink size={ 12 } strokeWidth={ 1.5 } />
				</a>
			</div>
		);
	}

	return (
		<div className="wpaim-plan-card">
			<div className="wpaim-plan-card__header">
				<FileText size={ 13 } strokeWidth={ 1.5 } />
				<span className="wpaim-plan-card__label">
					{ isUpdate
						? __( 'Proposed update', 'stilus' )
						: __( 'New post', 'stilus' ) }
				</span>
				{ ! isUpdate && plan.post_type && plan.post_type !== 'post' && (
					<span className="wpaim-plan-card__type-badge">
						{ plan.post_type }
					</span>
				) }
				<button
					className="wpaim-btn wpaim-btn--ghost wpaim-btn--icon wpaim-plan-card__dismiss"
					onClick={ onDismiss }
					aria-label={ __( 'Dismiss plan', 'stilus' ) }
					type="button"
				>
					<X size={ 12 } strokeWidth={ 1.5 } />
				</button>
			</div>

			<div className="wpaim-plan-card__body">
				{ isEditing ? (
					<>
						<label
							htmlFor="wpaim-plan-edit-title"
							className="wpaim-plan-card__field"
						>
							<span>{ __( 'Title', 'stilus' ) }</span>
							<input
								id="wpaim-plan-edit-title"
								type="text"
								value={ editTitle }
								onChange={ ( e ) =>
									setEditTitle( e.target.value )
								}
								className="wpaim-input"
							/>
						</label>
						<label
							htmlFor="wpaim-plan-edit-outline"
							className="wpaim-plan-card__field"
						>
							<span>
								{ isUpdate
									? __( 'Updated content', 'stilus' )
									: __( 'Content', 'stilus' ) }
							</span>
							<textarea
								id="wpaim-plan-edit-outline"
								value={ editContent }
								onChange={ ( e ) =>
									setEditContent( e.target.value )
								}
								rows={ 3 }
								className="wpaim-input"
							/>
						</label>
						<label
							htmlFor="wpaim-plan-edit-status"
							className="wpaim-plan-card__field wpaim-plan-card__field--inline"
						>
							<span>{ __( 'Status', 'stilus' ) }</span>
							<select
								id="wpaim-plan-edit-status"
								value={ editStatus }
								onChange={ ( e ) =>
									setEditStatus( e.target.value )
								}
								className="wpaim-input"
							>
								<option value="draft">
									{ __( 'Draft', 'stilus' ) }
								</option>
								<option value="publish">
									{ __( 'Published', 'stilus' ) }
								</option>
								<option value="pending">
									{ __( 'Pending review', 'stilus' ) }
								</option>
							</select>
						</label>
					</>
				) : (
					<>
						{ ! isUpdate && (
							<p className="wpaim-plan-card__title">
								{ plan.title }
							</p>
						) }
						{ isUpdate && plan.changes && (
							<p className="wpaim-plan-card__outline">
								{ plan.changes }
							</p>
						) }
						{ ! isUpdate && plan.outline && (
							<p className="wpaim-plan-card__outline">
								{ plan.outline }
							</p>
						) }
						{ plan.post_status && (
							<span className="wpaim-plan-card__status-badge">
								{ STATUS_LABELS[ plan.post_status ] ||
									plan.post_status }
							</span>
						) }
					</>
				) }
			</div>

			{ error && <p className="wpaim-plan-card__error">{ error }</p> }

			<div className="wpaim-plan-card__actions">
				<button
					className="wpaim-btn wpaim-btn--primary wpaim-btn--sm"
					onClick={ handleConfirm }
					disabled={ isExecuting }
					type="button"
				>
					{ isExecuting ? (
						<Loader2
							size={ 12 }
							strokeWidth={ 1.5 }
							className="wpaim-spinner"
						/>
					) : (
						confirmLabel
					) }
				</button>
				<button
					className="wpaim-btn wpaim-btn--ghost wpaim-btn--sm"
					onClick={ () => setIsEditing( ( v ) => ! v ) }
					type="button"
				>
					<Pencil size={ 12 } strokeWidth={ 1.5 } />
					{ isEditing
						? __( 'Cancel edit', 'stilus' )
						: __( 'Edit', 'stilus' ) }
				</button>
			</div>
		</div>
	);
}
