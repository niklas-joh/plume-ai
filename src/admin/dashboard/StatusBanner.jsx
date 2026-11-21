import { __ } from '@wordpress/i18n';
import { recordUpgradeClick } from '../../shared/telemetry';

/**
 * Contextual status banner shown at the top of the dashboard.
 *
 * Renders nothing when `bannerState` is `'none'`. Shows a warning when the
 * site is on the free Plugin API, or an error when the stored API key is
 * invalid. Each state surfaces the appropriate CTA links.
 *
 * @param {Object} props
 * @param {string} props.bannerState  Current banner variant: `'none'`, `'free_tier_low_credits'`, or `'invalid_key'`.
 * @param {Object} props.urls         URL map with at least `settings` and `upgrade` keys.
 * @return {ReactElement|null}
 */
export default function StatusBanner( { bannerState, urls } ) {
	if ( bannerState === 'none' ) {
		return null;
	}

	const isError = bannerState === 'invalid_key';

	return (
		<div
			className={ `plume-dash-banner plume-dash-banner--${
				isError ? 'error' : 'warning'
			}` }
		>
			<div className="plume-dash-banner__dot" />
			<div className="plume-dash-banner__text">
				{ isError ? (
					<>
						<strong>
							{ __(
								'Your API key appears to be invalid.',
								'plume'
							) }
						</strong>
						<span> { __( 'Check your Settings.', 'plume' ) }</span>
					</>
				) : (
					<>
						<strong>
							{ __(
								"You're running low on free credits.",
								'plume'
							) }
						</strong>
						<span>
							{ ' ' }
							{ __(
								'Add your own key for unlimited access, or upgrade to Pro.',
								'plume'
							) }
						</span>
					</>
				) }
			</div>
			{ ! isError && (
				<div className="plume-dash-banner__actions">
					<a href={ urls.settings } className="plume-dash-btn">
						{ __( 'Add API key', 'plume' ) }
					</a>
					<a
						href={ urls.upgrade }
						className="plume-dash-btn plume-dash-btn--primary"
						target="_blank"
						rel="nofollow noreferrer"
						onClick={ () =>
							recordUpgradeClick(
								'dashboard_banner',
								'dashboard'
							)
						}
					>
						{ __( 'Upgrade to Pro', 'plume' ) }
					</a>
				</div>
			) }
			{ isError && (
				<div className="plume-dash-banner__actions">
					<a
						href={ urls.settings }
						className="plume-dash-btn plume-dash-btn--primary"
					>
						{ __( 'Go to Settings', 'plume' ) }
					</a>
				</div>
			) }
		</div>
	);
}
