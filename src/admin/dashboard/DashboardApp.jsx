import StatusBanner from './StatusBanner';
import StartTiles from './StartTiles';
import UsageWidget from './UsageWidget';
import ResourceList from './ResourceList';
import PageFooter from './PageFooter';
import OnboardingPage from './OnboardingPage';
import './dashboard.css';

/**
 * Root dashboard application shown on the main Stilus admin page.
 *
 * Reads all page data from the `wpAiMindDashboard` global injected by PHP.
 * Renders the onboarding wizard in place of the normal dashboard when
 * `onboardingSeen` is false, so first-time users are guided through setup.
 *
 * @return {ReactElement}
 */
export default function DashboardApp() {
	const data = window.wpAiMindDashboard ?? {};
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
		<div className="stilus-dashboard">
			{ /* Top bar */ }
			<div className="stilus-dash-topbar">
				<div>
					<div className="stilus-dash-title">
						Stilus - Write and Design
					</div>
					<div className="stilus-dash-subtitle">
						AI-powered content creation for WordPress
					</div>
				</div>
				<span className="stilus-dash-version">v{ version }</span>
			</div>

			<StatusBanner bannerState={ bannerState } urls={ urls } />

			<div className="stilus-dash-body">
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
