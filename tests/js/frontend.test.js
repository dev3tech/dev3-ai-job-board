import { applyFixes, esc, h, modal, rest } from '../../src/frontend/util';
import { boardArgs, initApply } from '../../src/frontend/board';
import { moveCard, stageCards, initPipeline } from '../../src/frontend/pipeline';
import { addRow, fillProfile, initProfile } from '../../src/frontend/profile';

const flush = () => new Promise( ( r ) => setTimeout( r, 0 ) );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.fetch;
} );

const mockFetch = ( map ) => {
	window.fetch = jest.fn( ( url, opts ) => {
		const key = Object.keys( map ).find( ( k ) => url.includes( k ) );
		const body = key ? ( typeof map[ key ] === 'function' ? map[ key ]( url, opts ) : map[ key ] ) : {};
		return Promise.resolve( { ok: ! body.__error, status: body.__error ? 400 : 200, json: () => Promise.resolve( body ) } );
	} );
	return window.fetch;
};

describe( 'util', () => {
	it( 'builds REST urls and escapes', () => {
		expect( rest( 'jobs?q=a' ) ).toBe( '/wp-json/dev3jb/v1/jobs?q=a' );
		expect( esc( '<a href="x">' ) ).toBe( '&lt;a href=&quot;x&quot;&gt;' );
		expect( applyFixes( 'Ninjas wanted', [ { phrase: 'ninjas', suggestion: 'experts' } ] ) ).toBe( 'Experts wanted' );
	} );

	it( 'h() creates elements with listeners and text children', () => {
		const fn = jest.fn();
		const el = h( 'button', { class: 'x', onclick: fn, disabled: false }, 'Go', h( 'span', {}, '!' ) );
		el.click();
		expect( fn ).toHaveBeenCalled();
		expect( el.textContent ).toBe( 'Go!' );
		expect( el.hasAttribute( 'disabled' ) ).toBe( false );
	} );

	it( 'modal is labelled, closes on Escape and restores focus', () => {
		const opener = h( 'button', {}, 'open' );
		document.body.appendChild( opener );
		opener.focus();
		const dlg = modal( { title: 'Hello', body: h( 'input', { type: 'text' } ) } );
		const panel = document.querySelector( '[role="dialog"]' );
		expect( document.getElementById( panel.getAttribute( 'aria-labelledby' ) ).textContent ).toBe( 'Hello' );
		expect( document.activeElement.tagName ).toBe( 'INPUT' );
		document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape' } ) );
		expect( document.querySelector( '[role="dialog"]' ) ).toBeNull();
		expect( document.activeElement ).toBe( opener );
		expect( dlg.close ).toBeDefined();
	} );
} );

describe( 'board', () => {
	it( 'collects filters, fixed attributes, sort and page', () => {
		document.body.innerHTML = `<div data-dev3jb-board='{"fixed":{"type":"full-time"}}'><form data-dev3jb-search><input name="q" value="react"><select name="location"><option value="berlin" selected>Berlin</option></select><select name="category"><option value="" selected></option></select></form><select data-dev3jb-sort><option value="salary" selected>s</option></select></div>`;
		expect( boardArgs( document.querySelector( '[data-dev3jb-board]' ), 2 ) ).toEqual( { type: 'full-time', q: 'react', location: 'berlin', sort: 'salary', pg: 2 } );
	} );

	it( 'apply form checks consent before sending', async () => {
		const fetch = mockFetch( { 'listings/7/apply': { ok: true, message: 'Sent!' } } );
		document.body.innerHTML = `<form data-dev3jb-apply="7"><input name="name" required value="Ana"><input name="email" type="email" required value="ana@example.com"><input type="checkbox" name="consent" value="1"><p data-dev3jb-status></p><button type="submit">Send</button></form>`;
		const form = document.querySelector( 'form' );
		initApply( form );
		form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
		expect( fetch ).not.toHaveBeenCalled();
		form.querySelector( '[name="consent"]' ).checked = true;
		form.dispatchEvent( new window.Event( 'submit', { cancelable: true } ) );
		await flush();
		await flush();
		expect( fetch.mock.calls[ 0 ][ 0 ] ).toContain( 'listings/7/apply' );
		expect( fetch.mock.calls[ 0 ][ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
		expect( document.body.textContent ).toContain( 'Sent!' );
	} );
} );

describe( 'front-end pipeline', () => {
	const items = [
		{ id: 1, name: 'Ana', stage: 'new', position: 1, match_score: 55 },
		{ id: 2, name: 'Ben', stage: 'new', position: 2, match_score: 81 },
	];

	it( 'sorts and moves cards', () => {
		expect( stageCards( items, 'new', true ).map( ( a ) => a.id ) ).toEqual( [ 2, 1 ] );
		expect( stageCards( moveCard( items, 2, 'offer', -1 ), 'offer', false ).map( ( a ) => a.id ) ).toEqual( [ 2 ] );
	} );

	it( 'renders columns and posts a move', async () => {
		const fetch = mockFetch( {
			'employer/applications?job=4': { items, counts: {} },
			'employer/applications/1': { id: 1, card: { ...items[ 0 ], stage: 'screening' } },
		} );
		document.body.innerHTML = `<div data-dev3jb-pipeline='{"job":4,"match":true,"stages":{"new":"New","screening":"Screening","interview":"Interview","offer":"Offer","hired":"Hired","rejected":"Not a fit"},"i18n":{"moveTo":"Move to","decision":"Decision support only."}}'></div>`;
		initPipeline( document.querySelector( '[data-dev3jb-pipeline]' ) );
		await flush();
		await flush();
		expect( document.querySelectorAll( '.dev3jb-kanban__col' ) ).toHaveLength( 6 );
		expect( document.body.textContent ).toContain( 'Decision support only.' );
		expect( document.body.textContent ).toContain( '81%' );
		const select = document.querySelector( '.dev3jb-pcard__move select' );
		select.value = 'screening';
		select.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
		await flush();
		const call = fetch.mock.calls.find( ( c ) => c[ 1 ].method === 'POST' );
		expect( JSON.parse( call[ 1 ].body ) ).toEqual( { stage: 'screening', index: -1 } );
	} );
} );

describe( 'candidate profile', () => {
	const markup = `<form data-dev3jb-profile='{"i18n":{"filled":"Filled: %s","sections":{"skills":"skills"}}}'>
		<input type="hidden" data-dev3jb-file-id value="0"><input type="hidden" data-dev3jb-parsed value="0">
		<input data-dev3jb-p="headline" value=""><textarea data-dev3jb-p="summary"></textarea><input data-dev3jb-p="location" value=""><input data-dev3jb-p="skills" value="CSS">
		<fieldset data-dev3jb-repeat="experience"><div data-dev3jb-rows></div><template data-dev3jb-template><div data-dev3jb-row><input name="profile[experience][__i__][title]" data-k="title"><input type="checkbox" data-k="current"><button type="button" data-dev3jb-remove>x</button></div></template><button type="button" data-dev3jb-add>Add</button></fieldset>
		<span data-dev3jb-unsaved hidden></span></form>`;

	it( 'adds and removes repeater rows with unique names', () => {
		document.body.innerHTML = markup;
		const repeat = document.querySelector( '[data-dev3jb-repeat]' );
		const a = addRow( repeat, { title: 'Dev', current: true } );
		const b = addRow( repeat );
		expect( a.querySelector( 'input' ).name ).not.toBe( b.querySelector( 'input' ).name );
		expect( a.querySelector( '[data-k="title"]' ).value ).toBe( 'Dev' );
		expect( a.querySelector( '[data-k="current"]' ).checked ).toBe( true );
		initProfile( document.querySelector( 'form' ) );
		b.querySelector( '[data-dev3jb-remove]' ).click();
		expect( repeat.querySelectorAll( '[data-dev3jb-row]' ) ).toHaveLength( 1 );
		expect( document.querySelector( '[data-dev3jb-unsaved]' ).hidden ).toBe( false );
	} );

	it( 'fills the form from a parsed resume without saving', () => {
		document.body.innerHTML = markup;
		const form = document.querySelector( 'form' );
		const filled = fillProfile( form, { headline: 'Frontend Lead', summary: 'Builds apps.', skills: [ 'React', 'css' ], experience: [ { title: 'Lead', company: 'X', current: true } ] }, false );
		expect( filled ).toEqual( [ 'headline', 'summary', 'skills', 'experience' ] );
		expect( form.querySelector( '[data-dev3jb-p="skills"]' ).value ).toBe( 'CSS, React' );
		expect( form.querySelector( '[data-dev3jb-p="headline"]' ).classList.contains( 'is-filled' ) ).toBe( true );
		expect( form.querySelectorAll( '[data-dev3jb-rows] [data-dev3jb-row]' ) ).toHaveLength( 1 );
	} );
} );
