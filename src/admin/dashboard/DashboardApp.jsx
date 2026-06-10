import StatusBanner from './StatusBanner';
import StartTiles from './StartTiles';
import UsageWidget from './UsageWidget';
import ResourceList from './ResourceList';
import PageFooter from './PageFooter';
import OnboardingPage from './OnboardingPage';
import './dashboard.css';

/**
 * Root dashboard application shown on the main Plume admin page.
 *
 * Reads all page data from the `plumeDashboard` global injected by PHP.
 * Renders the onboarding wizard in place of the normal dashboard when
 * `onboardingSeen` is false, so first-time users are guided through setup.
 *
 * @return {ReactElement}
 */
export default function DashboardApp() {
	const data = window.plumeDashboard ?? {};
	const {
		bannerState = 'none',
		onboardingSeen = true,
		usage = null,
		version = '',
		nonce = '',
		restUrl = '',
		runSetupUrl = '#',
		urls = {},
		resourceUrls = {},
	} = data;

	if ( ! onboardingSeen ) {
		return (
			<OnboardingPage nonce={ nonce } restUrl={ restUrl } urls={ urls } />
		);
	}

	return (
		<div className="plume-dashboard">
			{ /* Top bar */ }
			<div className="plume-dash-topbar">
				<div>
					<div className="plume-dash-title">
						Plume - Write and Design
					</div>
					<div className="plume-dash-subtitle">
						AI-powered content creation for WordPress
					</div>
				</div>
				<span className="plume-dash-version">v{ version }</span>
			</div>

			<StatusBanner bannerState={ bannerState } urls={ urls } />

			<div className="plume-dash-body">
				<StartTiles urls={ urls } />
				<UsageWidget usage={ usage } />
				<ResourceList
					resourceUrls={ resourceUrls }
					version={ version }
				/>
			</div>

			<PageFooter urls={ urls } runSetupUrl={ runSetupUrl } />
		</div>
	);
}
