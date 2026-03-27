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
