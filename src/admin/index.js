import { render } from '@wordpress/element';
import ChatApp from './components/Chat/ChatApp';
import SettingsApp from './settings/SettingsApp';
import DashboardApp from './dashboard/DashboardApp';
import '../styles/tokens.css';
import './admin.css';

const chatRoot = document.getElementById( 'stilus-chat' );
if ( chatRoot ) {
	render( <ChatApp />, chatRoot );
}

const settingsRoot = document.getElementById( 'stilus-settings' );
if ( settingsRoot ) {
	render( <SettingsApp />, settingsRoot );
}

const dashboardRoot = document.getElementById( 'stilus-dashboard' );
if ( dashboardRoot ) {
	render( <DashboardApp />, dashboardRoot );
}
