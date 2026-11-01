import '../frontend/frontend.scss';
import { ready } from '../frontend/util';
import { initApply, initBoard, initSave } from '../frontend/board';

ready( () => {
	document.querySelectorAll( '[data-dev3jb-board]' ).forEach( initBoard );
	document.querySelectorAll( '[data-dev3jb-apply]' ).forEach( initApply );
	initSave();
	// Confirm destructive front-end actions.
	document.addEventListener( 'submit', ( e ) => {
		const form = e.target.closest( '[data-dev3jb-confirm]' );
		if ( form && ! window.confirm( form.getAttribute( 'data-dev3jb-confirm' ) ) ) { // eslint-disable-line no-alert
			e.preventDefault();
		}
	} );
	document.querySelectorAll( '[data-dev3jb-autosubmit]' ).forEach( ( el ) => el.addEventListener( 'change', () => el.form && el.form.submit() ) );
} );
