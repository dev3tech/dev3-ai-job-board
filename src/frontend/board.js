import { api, cfg, h, modal, t } from './util';

const KEYS = [ 'q', 'location', 'category', 'type', 'workplace', 'salary_min', 'posted' ];

/**
 * Current filters of a board (form + sort + page).
 *
 * @param {HTMLElement} root Board.
 * @param {number}      page Page.
 * @return {Object} Args.
 */
export function boardArgs( root, page = 1 ) {
	const form = root.querySelector( '[data-dev3jb-search]' );
	const conf = JSON.parse( root.getAttribute( 'data-dev3jb-board' ) || '{}' );
	const args = { ...( conf.fixed || {} ) };
	if ( form ) {
		new window.FormData( form ).forEach( ( value, name ) => {
			if ( KEYS.includes( name ) && value !== '' ) {
				args[ name ] = value;
			}
		} );
	}
	const sort = root.querySelector( '[data-dev3jb-sort]' );
	if ( sort && sort.value && sort.value !== 'date' ) {
		args.sort = sort.value;
	}
	if ( page > 1 ) {
		args.pg = page;
	}
	return args;
}

/**
 * Wires one job board: AJAX filtering with URL state, paging, sort, alert dialog.
 *
 * @param {HTMLElement} root Board.
 */
export function initBoard( root ) {
	const conf = JSON.parse( root.getAttribute( 'data-dev3jb-board' ) || '{}' );
	const results = root.querySelector( '[data-dev3jb-results]' );
	const form = root.querySelector( '[data-dev3jb-search]' );
	let controller = null;

	const load = ( page = 1, push = true ) => {
		const args = boardArgs( root, page );
		const params = new URLSearchParams( { ...args, html: '1', per_page: conf.per_page || '', base: conf.base || '' } );
		if ( controller ) {
			controller.abort();
		}
		controller = typeof window.AbortController !== 'undefined' ? new window.AbortController() : null;
		results.setAttribute( 'aria-busy', 'true' );
		results.classList.add( 'is-loading' );
		api( `listings?${ params.toString() }`, { signal: controller ? controller.signal : undefined } )
			.then( ( r ) => {
				results.innerHTML = r.html;
				if ( push && window.history && window.history.pushState ) {
					const url = new URL( conf.base || window.location.href, window.location.href );
					Object.keys( args ).forEach( ( k ) => {
						if ( ! ( conf.fixed && conf.fixed[ k ] === args[ k ] ) ) {
							url.searchParams.set( k, args[ k ] );
						}
					} );
					window.history.pushState( { dev3jb: true }, '', url.toString() );
				}
				if ( page > 1 || push === 'scroll' ) {
					root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} )
			.catch( ( e ) => {
				if ( e.name !== 'AbortError' ) {
					results.insertAdjacentHTML( 'afterbegin', `<div class="dev3jb-notice dev3jb-notice--error" role="alert">${ e.message }</div>` );
				}
			} )
			.finally( () => {
				results.removeAttribute( 'aria-busy' );
				results.classList.remove( 'is-loading' );
			} );
	};

	if ( form ) {
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			load( 1 );
		} );
		form.addEventListener( 'change', ( e ) => {
			if ( e.target.matches( 'select' ) ) {
				load( 1 );
			}
		} );
		const reset = form.querySelector( '[data-dev3jb-reset]' );
		if ( reset ) {
			reset.addEventListener( 'click', ( e ) => {
				e.preventDefault();
				form.reset();
				form.querySelectorAll( 'select' ).forEach( ( s ) => ( s.value = '' ) );
				form.querySelectorAll( 'input[type="search"]' ).forEach( ( i ) => ( i.value = '' ) );
				load( 1 );
			} );
		}
	}
	root.addEventListener( 'change', ( e ) => {
		if ( e.target.matches( '[data-dev3jb-sort]' ) ) {
			load( 1 );
		}
	} );
	root.addEventListener( 'click', ( e ) => {
		const pager = e.target.closest( '[data-dev3jb-page]' );
		if ( pager ) {
			e.preventDefault();
			load( Number( pager.getAttribute( 'data-dev3jb-page' ) ), true );
			return;
		}
		if ( e.target.closest( '[data-dev3jb-alert-open]' ) ) {
			e.preventDefault();
			openAlert( boardArgs( root ) );
		}
	} );
	window.addEventListener( 'popstate', () => {
		const url = new URL( window.location.href );
		if ( form ) {
			KEYS.forEach( ( k ) => {
				const el = form.elements[ k ];
				if ( el ) {
					el.value = url.searchParams.get( k ) || '';
				}
			} );
		}
		load( Number( url.searchParams.get( 'pg' ) ) || 1, false );
	} );
}

/**
 * "Email me jobs like these" dialog.
 *
 * @param {Object} query Current search.
 */
export function openAlert( query ) {
	const logged = !! cfg().loggedIn;
	const status = h( 'p', { class: 'dev3jb-form__status', role: 'status', 'aria-live': 'polite' } );
	const email = h( 'input', { type: 'email', id: 'dev3jb-alert-email', name: 'email', required: true, autocomplete: 'email' } );
	const freq = h( 'select', { id: 'dev3jb-alert-freq', name: 'frequency' }, h( 'option', { value: 'daily' }, t( 'daily' ) ), h( 'option', { value: 'weekly' }, t( 'weekly' ) ) );
	const submit = h( 'button', { type: 'submit', class: 'dev3jb-btn dev3jb-btn--primary' }, t( 'createAlert' ) );
	let dlg = null;
	const form = h(
		'form',
		{
			class: 'dev3jb-form',
			novalidate: true,
			onsubmit: ( e ) => {
				e.preventDefault();
				if ( ! logged && ! email.checkValidity() ) {
					email.focus();
					status.textContent = t( 'required' );
					return;
				}
				submit.disabled = true;
				api( 'alerts', { method: 'POST', data: { query, email: email.value, frequency: freq.value } } )
					.then( ( r ) => {
						form.replaceWith( h( 'div', { class: 'dev3jb-notice dev3jb-notice--ok', role: 'status' }, r.confirmed ? t( 'alertSaved' ) : t( 'alertConfirm' ) ) );
						window.setTimeout( () => dlg && dlg.close(), 2600 );
					} )
					.catch( ( err ) => {
						status.textContent = err.message;
						submit.disabled = false;
					} );
			},
		},
		h( 'p', { class: 'dev3jb-muted' }, t( 'alertText' ) ),
		logged ? null : h( 'div', { class: 'dev3jb-field' }, h( 'label', { for: 'dev3jb-alert-email' }, t( 'email' ) ), email ),
		h( 'div', { class: 'dev3jb-field' }, h( 'label', { for: 'dev3jb-alert-freq' }, t( 'frequency' ) ), freq ),
		status,
		h( 'div', { class: 'dev3jb-row' }, submit, h( 'button', { type: 'button', class: 'dev3jb-btn dev3jb-btn--ghost', onclick: () => dlg.close() }, t( 'cancel' ) ) )
	);
	dlg = modal( { title: t( 'alertTitle' ), body: form } );
}

/**
 * Save-job hearts (logged-in users).
 */
export function initSave() {
	document.addEventListener( 'click', ( e ) => {
		const btn = e.target.closest( '[data-dev3jb-save]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		const id = btn.getAttribute( 'data-dev3jb-save' );
		btn.disabled = true;
		api( `listings/${ id }/save`, { method: 'POST', data: {} } )
			.then( ( r ) => {
				document.querySelectorAll( `[data-dev3jb-save="${ id }"]` ).forEach( ( b ) => b.setAttribute( 'aria-pressed', r.saved ? 'true' : 'false' ) );
			} )
			.catch( () => {} )
			.finally( () => ( btn.disabled = false ) );
	} );
}

/**
 * Apply form (multipart REST).
 *
 * @param {HTMLFormElement} form Form.
 */
export function initApply( form ) {
	const status = form.querySelector( '[data-dev3jb-status]' );
	const button = form.querySelector( 'button[type="submit"]' );
	form.addEventListener( 'submit', ( e ) => {
		e.preventDefault();
		const say = ( msg, bad = true ) => {
			status.textContent = msg;
			status.classList.toggle( 'is-error', bad );
		};
		const required = [ ...form.querySelectorAll( '[required]' ) ].filter( ( el ) => el.type !== 'checkbox' );
		const missing = required.find( ( el ) => ! el.checkValidity() );
		if ( missing ) {
			missing.setAttribute( 'aria-invalid', 'true' );
			missing.focus();
			say( t( 'required' ) );
			return;
		}
		const consent = form.querySelector( 'input[name="consent"]' );
		if ( consent && ! consent.checked ) {
			consent.focus();
			say( t( 'consent' ) );
			return;
		}
		const file = form.querySelector( 'input[type="file"]' );
		if ( file && file.files[ 0 ] && cfg().maxUpload && file.files[ 0 ].size > cfg().maxUpload ) {
			file.focus();
			say( t( 'fileTooBig' ) );
			return;
		}
		button.disabled = true;
		say( t( 'sending' ), false );
		api( `listings/${ form.getAttribute( 'data-dev3jb-apply' ) }/apply`, { method: 'POST', body: new window.FormData( form ) } )
			.then( ( r ) => {
				const done = h( 'div', { class: 'dev3jb-notice dev3jb-notice--ok', role: 'status', tabindex: '-1' }, r.message || t( 'sent' ) );
				form.replaceWith( done );
				done.focus();
			} )
			.catch( ( err ) => {
				say( err.message );
				button.disabled = false;
			} );
	} );
}
