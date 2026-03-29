import StatusBanner from './StatusBanner';
import StartTiles from './StartTiles';
import ResourceList from './ResourceList';
import PageFooter from './PageFooter';
import OnboardingPage from './OnboardingPage';
import './dashboard.css';

export default function DashboardApp() {
    const data = window.wpAiMindDashboard ?? {};
    const {
        bannerState    = 'none',
        onboardingSeen = true,
        version        = '',
        nonce          = '',
        restUrl        = '',
        runSetupUrl    = '#',
        urls           = {},
        resourceUrls   = {},
    } = data;

    if ( ! onboardingSeen ) {
        return (
            <OnboardingPage
                nonce={ nonce }
                restUrl={ restUrl }
                urls={ urls }
            />
        );
    }

    return (
        <div className="wpaim-dashboard">
            { /* Top bar */ }
            <div className="wpaim-dash-topbar">
                <div>
                    <div className="wpaim-dash-title">WP AI Mind</div>
                    <div className="wpaim-dash-subtitle">AI-powered content creation for WordPress</div>
                </div>
                <span className="wpaim-dash-version">v{ version }</span>
            </div>

            <StatusBanner bannerState={ bannerState } urls={ urls } />

            <div className="wpaim-dash-body">
                <StartTiles urls={ urls } />
                <ResourceList resourceUrls={ resourceUrls } version={ version } />
            </div>

            <PageFooter urls={ urls } runSetupUrl={ runSetupUrl } />
        </div>
    );
}
