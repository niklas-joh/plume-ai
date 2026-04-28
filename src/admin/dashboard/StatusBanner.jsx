/**
 * Contextual status banner shown at the top of the dashboard.
 *
 * Renders nothing when `bannerState` is `'none'`. Shows a warning when the
 * site is on the free Plugin API, or an error when the stored API key is
 * invalid. Each state surfaces the appropriate CTA links.
 *
 * @param {Object} props
 * @param {string} props.bannerState  Current banner variant: `'none'`, `'free_tier'`, or `'invalid_key'`.
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
			className={ `wpaim-dash-banner wpaim-dash-banner--${
				isError ? 'error' : 'warning'
			}` }
		>
			<div className="wpaim-dash-banner__dot" />
			<div className="wpaim-dash-banner__text">
				{ isError ? (
					<>
						<strong>Your API key appears to be invalid.</strong>
						<span> Check your Settings.</span>
					</>
				) : (
					<>
						<strong>You are on the free Plugin API.</strong>
						<span>
							{ ' ' }
							Add your own key for unlimited access, or upgrade to
							Pro.
						</span>
					</>
				) }
			</div>
			{ ! isError && (
				<div className="wpaim-dash-banner__actions">
					<a href={ urls.settings } className="wpaim-dash-btn">
						Add API key
					</a>
					<a
						href={ urls.upgrade }
						className="wpaim-dash-btn wpaim-dash-btn--primary"
						target="_blank"
						rel="nofollow noreferrer"
					>
						Upgrade to Pro
					</a>
				</div>
			) }
			{ isError && (
				<div className="wpaim-dash-banner__actions">
					<a
						href={ urls.settings }
						className="wpaim-dash-btn wpaim-dash-btn--primary"
					>
						Go to Settings
					</a>
				</div>
			) }
		</div>
	);
}
