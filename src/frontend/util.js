/**
 * Small front-end helpers (no dependencies).
 */
export const cfg = () => window.dev3jbConfig || {};
export const t = ( key ) => ( cfg().i18n && cfg().i18n[ key ] ) || '';

/**
 * REST URL that works with plain and pretty permalinks.
 *
 * @param {string} path Route.
 * @return {string} URL.
 */
export function rest( path ) {
	const base = cfg().restUrl || '/wp-json/dev3jb/v1/';
	const [ route, query ] = String( path ).replace( /^\//, '' ).split( /\?(.*)/s );
	const url = base + route;
	return query ? url + ( url.includes( '?' ) ? '&' : '?' ) + query : url;
}

/**
 * fetch() wrapper returning JSON or throwing an Error with the server message.
 *
 * @param {string} path    Route.
 * @param {Object} options { method, data (JSON), body (FormData), signal }.
 * @return {Promise<Object>} JSON.
 */
export function api( path, options = {} ) {
	const opts = { credentials: 'same-origin', method: options.method || 'GET', headers: {} };
	if ( cfg().nonce ) {
		opts.headers[ 'X-WP-Nonce' ] = cfg().nonce;
	}
	if ( options.data ) {
		opts.headers[ 'Content-Type' ] = 'application/json';
		opts.body = JSON.stringify( options.data );
	} else if ( options.body ) {
		opts.body = options.body;
	}
	if ( options.signal ) {
		opts.signal = options.signal;
	}
	return window.fetch( rest( path ), opts ).then( ( res ) =>
		res
			.json()
			.catch( () => ( {} ) )
			.then( ( json ) => {
				if ( ! res.ok ) {
					const err = new Error( ( json && json.message ) || t( 'error' ) || 'Error' );
					err.code = json && json.code;
					err.status = res.status;
					throw err;
				}
				return json;
			} )
	);
}

export const ready = ( fn ) => ( document.readyState === 'loading' ? document.addEventListener( 'DOMContentLoaded', fn ) : fn() );

/**
 * Escapes text for innerHTML.
 *
 * @param {string} s Text.
 * @return {string} Safe.
 */
export const esc = ( s ) => String( s === null || s === undefined ? '' : s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

/**
 * Creates an element: h( 'button', { class: 'x', onclick: fn, type: 'button' }, 'Text', child ).
 *
 * @param {string} tag      Tag.
 * @param {Object} attrs    Attributes (on* = listeners, html = innerHTML from trusted markup).
 * @param {...*}   children Children (strings become text nodes).
 * @return {HTMLElement} Element.
 */
export function h( tag, attrs = {}, ...children ) {
	const el = document.createElement( tag );
	Object.keys( attrs || {} ).forEach( ( k ) => {
		const v = attrs[ k ];
		if ( v === null || v === undefined || v === false ) {
			return;
		}
		if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
			el.addEventListener( k.slice( 2 ), v );
		} else if ( k === 'html' ) {
			el.innerHTML = v;
		} else if ( k === 'class' ) {
			el.className = v;
		} else if ( k === 'value' ) {
			el.value = v;
		} else {
			el.setAttribute( k, v === true ? '' : v );
		}
	} );
	children.flat().forEach( ( c ) => {
		if ( c === null || c === undefined || c === false ) {
			return;
		}
		el.appendChild( c instanceof window.Node ? c : document.createTextNode( String( c ) ) );
	} );
	return el;
}

/**
 * Applies inclusive-language fixes to HTML (case-insensitive, keeps a leading capital).
 *
 * @param {string} html  HTML.
 * @param {Array}  fixes [ { phrase, suggestion } ].
 * @return {string} HTML.
 */
export function applyFixes( html, fixes ) {
	let out = String( html || '' );
	( fixes || [] ).forEach( ( fix ) => {
		if ( ! fix.phrase ) {
			return;
		}
		const re = new RegExp( fix.phrase.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'gi' );
		out = out.replace( re, ( m ) => {
			const rep = fix.suggestion || '';
			if ( ! rep ) {
				return '';
			}
			const first = m.charAt( 0 );
			return first !== first.toLowerCase() ? rep.charAt( 0 ).toUpperCase() + rep.slice( 1 ) : rep;
		} );
	} );
	return out.replace( / {2,}/g, ' ' );
}

/**
 * Minimal accessible modal dialog (focus trap, Escape, focus restore).
 *
 * @param {Object}      opts         Options.
 * @param {string}      opts.title   Title.
 * @param {HTMLElement} opts.body    Body.
 * @param {string}      opts.size    sm|lg.
 * @param {Function}    opts.onClose Called after close.
 * @return {{ el: HTMLElement, close: Function }} Dialog.
 */
export function modal( { title, body, size = 'sm', onClose } ) {
	const previous = document.activeElement;
	const titleId = 'dev3jb-dlg-' + Math.random().toString( 36 ).slice( 2, 8 );
	const close = () => {
		document.removeEventListener( 'keydown', onKey, true );
		wrap.remove();
		document.documentElement.classList.remove( 'dev3jb-lock' );
		if ( previous && previous.focus ) {
			previous.focus();
		}
		if ( onClose ) {
			onClose();
		}
	};
	const panel = h(
		'div',
		{ class: `dev3jb-dialog dev3jb-dialog--${ size }`, role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1' },
		h( 'div', { class: 'dev3jb-dialog__head' }, h( 'h2', { id: titleId, class: 'dev3jb-h' }, title ), h( 'button', { type: 'button', class: 'dev3jb-iconbtn', 'aria-label': t( 'close' ) || 'Close', onclick: close, html: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>' } ) ),
		h( 'div', { class: 'dev3jb-dialog__body' }, body )
	);
	const wrap = h(
		'div',
		{
			class: 'dev3jb dev3jb-overlay',
			onmousedown: ( e ) => {
				if ( e.target === wrap ) {
					close();
				}
			},
		},
		panel
	);
	const onKey = ( e ) => {
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			close();
		} else if ( e.key === 'Tab' ) {
			const f = [ ...panel.querySelectorAll( 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select,textarea,[tabindex]:not([tabindex="-1"])' ) ].filter( ( x ) => x.offsetParent !== null );
			if ( ! f.length ) {
				return;
			}
			if ( e.shiftKey && document.activeElement === f[ 0 ] ) {
				e.preventDefault();
				f[ f.length - 1 ].focus();
			} else if ( ! e.shiftKey && document.activeElement === f[ f.length - 1 ] ) {
				e.preventDefault();
				f[ 0 ].focus();
			}
		}
	};
	document.addEventListener( 'keydown', onKey, true );
	document.body.appendChild( wrap );
	document.documentElement.classList.add( 'dev3jb-lock' );
	const first = panel.querySelector( 'input:not([type="hidden"]),select,textarea,button.dev3jb-btn' );
	( first || panel ).focus();
	return { el: panel, close };
}

/**
 * Localised date from a GMT MySQL datetime.
 *
 * @param {string} value Date.
 * @return {string} Date.
 */
export function formatDate( value ) {
	if ( ! value ) {
		return '';
	}
	const d = new Date( String( value ).replace( ' ', 'T' ) + 'Z' );
	return isNaN( d.getTime() ) ? value : d.toLocaleDateString( undefined, { dateStyle: 'medium' } );
}
