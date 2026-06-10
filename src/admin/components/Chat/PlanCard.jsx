import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { FileText, Pencil, X, ExternalLink, Loader2 } from 'lucide-react';

const STATUS_LABELS = {
	draft: __( 'Draft', 'plume' ),
	publish: __( 'Published', 'plume' ),
	pending: __( 'Pending review', 'plume' ),
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
				path: `/plume/v1/plans/${ plan.id }/execute`,
				method: 'POST',
				data: body,
			} );
			setEditUrl( result.edit_url );
		} catch ( err ) {
			setError(
				err?.message ??
					__( 'Something went wrong. Please try again.', 'plume' )
			);
		} finally {
			setIsExecuting( false );
		}
	}

	const confirmLabel = isUpdate
		? __( 'Apply update', 'plume' )
		: __( 'Create post', 'plume' );

	if ( editUrl ) {
		return (
			<div className="plume-plan-card plume-plan-card--done">
				<span className="plume-plan-card__done-text">
					{ isUpdate
						? __( 'Post updated.', 'plume' )
						: __( 'Post created.', 'plume' ) }
				</span>
				<a
					href={ editUrl }
					target="_blank"
					rel="noreferrer"
					className="plume-btn plume-btn--ghost plume-btn--sm"
				>
					{ __( 'Edit post', 'plume' ) }
					<ExternalLink size={ 12 } strokeWidth={ 1.5 } />
				</a>
			</div>
		);
	}

	return (
		<div className="plume-plan-card">
			<div className="plume-plan-card__header">
				<FileText size={ 13 } strokeWidth={ 1.5 } />
				<span className="plume-plan-card__label">
					{ isUpdate
						? __( 'Proposed update', 'plume' )
						: __( 'New post', 'plume' ) }
				</span>
				{ ! isUpdate && plan.post_type && plan.post_type !== 'post' && (
					<span className="plume-plan-card__type-badge">
						{ plan.post_type }
					</span>
				) }
				<button
					className="plume-btn plume-btn--ghost plume-btn--icon plume-plan-card__dismiss"
					onClick={ onDismiss }
					aria-label={ __( 'Dismiss plan', 'plume' ) }
					type="button"
				>
					<X size={ 12 } strokeWidth={ 1.5 } />
				</button>
			</div>

			<div className="plume-plan-card__body">
				{ isEditing ? (
					<>
						<label
							htmlFor="plume-plan-edit-title"
							className="plume-plan-card__field"
						>
							<span>{ __( 'Title', 'plume' ) }</span>
							<input
								id="plume-plan-edit-title"
								type="text"
								value={ editTitle }
								onChange={ ( e ) =>
									setEditTitle( e.target.value )
								}
								className="plume-input"
							/>
						</label>
						<label
							htmlFor="plume-plan-edit-outline"
							className="plume-plan-card__field"
						>
							<span>
								{ isUpdate
									? __( 'Updated content', 'plume' )
									: __( 'Content', 'plume' ) }
							</span>
							<textarea
								id="plume-plan-edit-outline"
								value={ editContent }
								onChange={ ( e ) =>
									setEditContent( e.target.value )
								}
								rows={ 3 }
								className="plume-input"
							/>
						</label>
						<label
							htmlFor="plume-plan-edit-status"
							className="plume-plan-card__field plume-plan-card__field--inline"
						>
							<span>{ __( 'Status', 'plume' ) }</span>
							<select
								id="plume-plan-edit-status"
								value={ editStatus }
								onChange={ ( e ) =>
									setEditStatus( e.target.value )
								}
								className="plume-input"
							>
								<option value="draft">
									{ __( 'Draft', 'plume' ) }
								</option>
								<option value="publish">
									{ __( 'Published', 'plume' ) }
								</option>
								<option value="pending">
									{ __( 'Pending review', 'plume' ) }
								</option>
							</select>
						</label>
					</>
				) : (
					<>
						{ ! isUpdate && (
							<p className="plume-plan-card__title">
								{ plan.title }
							</p>
						) }
						{ isUpdate && plan.changes && (
							<p className="plume-plan-card__outline">
								{ plan.changes }
							</p>
						) }
						{ ! isUpdate && plan.outline && (
							<p className="plume-plan-card__outline">
								{ plan.outline }
							</p>
						) }
						{ plan.post_status && (
							<span className="plume-plan-card__status-badge">
								{ STATUS_LABELS[ plan.post_status ] ||
									plan.post_status }
							</span>
						) }
					</>
				) }
			</div>

			{ error && <p className="plume-plan-card__error">{ error }</p> }

			<div className="plume-plan-card__actions">
				<button
					className="plume-btn plume-btn--primary plume-btn--sm"
					onClick={ handleConfirm }
					disabled={ isExecuting }
					type="button"
				>
					{ isExecuting ? (
						<Loader2
							size={ 12 }
							strokeWidth={ 1.5 }
							className="plume-spinner"
						/>
					) : (
						confirmLabel
					) }
				</button>
				<button
					className="plume-btn plume-btn--ghost plume-btn--sm"
					onClick={ () => setIsEditing( ( v ) => ! v ) }
					type="button"
				>
					<Pencil size={ 12 } strokeWidth={ 1.5 } />
					{ isEditing
						? __( 'Cancel edit', 'plume' )
						: __( 'Edit', 'plume' ) }
				</button>
			</div>
		</div>
	);
}
