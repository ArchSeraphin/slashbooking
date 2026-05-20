import './styles.scss';
import { createRoot } from '@wordpress/element';
import App from './App';

const mount = document.getElementById( 'tb-admin-app' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
