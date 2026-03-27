import { render } from '@wordpress/element';
import GeneratorWizard from './components/GeneratorWizard';
import '../styles/tokens.css';
import './generator.css';

const root = document.getElementById( 'wp-ai-mind-generator' );
if ( root ) {
	render( <GeneratorWizard />, root );
}
