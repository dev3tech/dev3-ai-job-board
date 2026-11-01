import apiFetch from '@wordpress/api-fetch';
import { AppContext, applyFixes, money, scoreTone, stripTags, termOptions } from '../../src/admin/shared';
import { htmlToSimple, simpleToHtml } from '../../src/admin/JobEditor';
import JobEditor from '../../src/admin/JobEditor';
import Pipeline, { moveLocal, stageItems } from '../../src/admin/Pipeline';
import { MatchCard } from '../../src/admin/ApplicationDrawer';
import Dashboard, { DailyChart } from '../../src/admin/Dashboard';
import { ToastProvider } from '../../src/core3/ui';
import { change, click, render } from './render';

const META = {
	terms: { categories: [ { id: 1, name: 'Design', parent: 0, slug: 'design' }, { id: 2, name: 'UX', parent: 1, slug: 'ux' } ], types: [ { id: 5, name: 'Full-time', parent: 0 } ], locations: [ { id: 9, name: 'Berlin', parent: 0 } ], skills: [] },
	companies: [ { value: 3, label: 'Brightwave' } ],
	employers: [],
	stages: { new: 'New', screening: 'Screening', interview: 'Interview', offer: 'Offer', hired: 'Hired', rejected: 'Not a fit', withdrawn: 'Withdrawn' },
	workplaces: { onsite: 'On-site', hybrid: 'Hybrid', remote: 'Remote' },
	levels: { mid: 'Mid level', senior: 'Senior' },
	periods: { year: 'per year' },
	sizes: [ '' ],
	packages: [],
	currency: 'USD',
	ai: { enabled: true, key_set: false, writer: false, match: false, parse: false, indexed: { job: 0, resume: 0 } },
	urls: { jobs: '/jobs/' },
};

const withApp = ( el, extra = {} ) => (
	<ToastProvider>
		<AppContext.Provider value={ { meta: META, settings: {}, reloadMeta: () => {}, save: () => Promise.resolve(), onUsage: () => {}, navigate: jest.fn(), setBadges: () => {}, ...extra } }>{ el }</AppContext.Provider>
	</ToastProvider>
);

const route = ( map ) =>
	apiFetch.mockImplementation( ( opts ) => {
		const key = Object.keys( map ).find( ( k ) => opts.url.includes( k ) );
		return key ? Promise.resolve( typeof map[ key ] === 'function' ? map[ key ]( opts ) : map[ key ] ) : new Promise( () => {} );
	} );

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
} );

describe( 'shared helpers', () => {
	it( 'converts simple markup to job HTML and back', () => {
		const text = 'We build tools.\n\n## What you will do\n- Ship features\n- Review code';
		const html = simpleToHtml( text );
		expect( html ).toBe( '<p>We build tools.</p>\n<h3>What you will do</h3>\n<ul>\n<li>Ship features</li>\n<li>Review code</li>\n</ul>' );
		expect( htmlToSimple( html ) ).toBe( text );
		expect( simpleToHtml( '<b>x</b>' ) ).toBe( '<p>&lt;b&gt;x&lt;/b&gt;</p>' );
	} );

	it( 'applies inclusive-language fixes and keeps capitals', () => {
		expect( applyFixes( 'Rockstar wanted. Join our rockstar team.', [ { phrase: 'rockstar', suggestion: 'expert' } ] ) ).toBe( 'Expert wanted. Join our expert team.' );
		expect( applyFixes( 'A young team', [ { phrase: 'young', suggestion: '' } ] ) ).toBe( 'A team' );
	} );

	it( 'formats money, tones, terms and text', () => {
		expect( money( 1200, 'USD' ) ).toMatch( /1,200/ );
		expect( scoreTone( 80 ) ).toBe( 'success' );
		expect( scoreTone( 60 ) ).toBe( 'warning' );
		expect( scoreTone( null ) ).toBe( 'neutral' );
		expect( termOptions( META.terms.categories, 'All' ).map( ( o ) => o.label ) ).toEqual( [ 'All', 'Design', '— UX' ] );
		expect( stripTags( '<p>a</p><ul><li>b</li></ul>' ) ).toBe( 'a\nb' );
	} );
} );

describe( 'pipeline', () => {
	const items = [
		{ id: 1, name: 'Ana Lima', stage: 'new', position: 1, match_score: 40, rating: 0, created_at: '2026-10-20 10:00:00' },
		{ id: 2, name: 'Ben Ode', stage: 'new', position: 2, match_score: 90, rating: 3, created_at: '2026-10-21 10:00:00' },
		{ id: 3, name: 'Cy Park', stage: 'interview', position: 1, match_score: null, rating: 0, created_at: '2026-10-22 10:00:00' },
	];

	it( 'sorts by board order or best match', () => {
		expect( stageItems( items, 'new', false ).map( ( a ) => a.id ) ).toEqual( [ 1, 2 ] );
		expect( stageItems( items, 'new', true ).map( ( a ) => a.id ) ).toEqual( [ 2, 1 ] );
	} );

	it( 'moves a card to an index in another stage', () => {
		const next = moveLocal( items, 1, 'interview', 0 );
		expect( stageItems( next, 'interview', false ).map( ( a ) => a.id ) ).toEqual( [ 1, 3 ] );
		expect( stageItems( next, 'new', false ).map( ( a ) => a.id ) ).toEqual( [ 2 ] );
	} );

	it( 'renders every stage and moves with the accessible select', async () => {
		const onMove = jest.fn();
		const onOpen = jest.fn();
		const view = await render( <Pipeline items={ items } labels={ META.stages } onMove={ onMove } onOpen={ onOpen } /> );
		expect( view.$$( '.dev3jb-kanban__col' ) ).toHaveLength( 6 );
		expect( view.getByText( '90% match' ) ).toBeTruthy();
		const select = view.$$( '.dev3jb-kcard__move select' )[ 0 ];
		await change( select, 'offer' );
		expect( onMove ).toHaveBeenCalledWith( 1, 'offer', -1 );
		await click( view.$( '.dev3jb-kcard__open' ) );
		expect( onOpen ).toHaveBeenCalledWith( 1 );
		view.unmount();
	} );
} );

describe( 'match card', () => {
	it( 'shows criteria, strengths and the decision-support notice', async () => {
		const match = { score: 78, criteria: { skills: 80, experience: 100, role: 50, location: 100 }, strengths: [ 'Has React' ], gaps: [ 'No GraphQL' ], summary: 'Strong fit.', source: 'rules' };
		const onScore = jest.fn();
		const view = await render( <MatchCard match={ match } ai={ false } busy="" onScore={ onScore } /> );
		expect( view.getByText( '78' ) ).toBeTruthy();
		expect( view.getByText( 'Has React' ) ).toBeTruthy();
		expect( view.container.textContent ).toMatch( /Decision support only/ );
		expect( view.queryByText( 'Explain with DEV3 AI' ) ).toBeNull();
		await click( view.getByText( 'Recalculate (rules)' ) );
		expect( onScore ).toHaveBeenCalledWith( false );
		view.unmount();
	} );
} );

describe( 'dashboard', () => {
	it( 'renders KPIs, the chart and the connect notice', async () => {
		route( {
			'admin/stats': {
				jobs: { publish: 12, pending: 2, draft: 0, dev3jb_unpaid: 0, dev3jb_expired: 1 },
				applications_30: 40,
				applications_7: 9,
				revenue_30: 158,
				candidates: 21,
				daily: [ { date: '2026-10-24', count: 3 }, { date: '2026-10-25', count: 5 } ],
				stages: { new: 5, screening: 3, interview: 2, offer: 1, hired: 1, rejected: 2 },
				recent: [],
				top: [],
				pending: [],
			},
		} );
		const view = await render( withApp( <Dashboard /> ) );
		await view.flush();
		expect( view.getByText( '12' ) ).toBeTruthy();
		expect( view.getByText( 'Connect DEV3 AI' ) ).toBeTruthy();
		expect( view.$$( '.dev3jb-chart__col' ) ).toHaveLength( 2 );
		view.unmount();
	} );

	it( 'chart columns have accessible labels and a hover tooltip', async () => {
		const view = await render( <DailyChart days={ [ { date: '2026-10-25', count: 4 } ] } /> );
		const col = view.$( '.dev3jb-chart__col' );
		expect( col.getAttribute( 'aria-label' ) ).toMatch( /4 applications/ );
		await click( col );
		view.unmount();
	} );
} );

describe( 'job editor', () => {
	it( 'drafts with the writer and applies the accepted draft', async () => {
		route( {
			'admin/jobs/5': { id: 5, title: 'Designer', content: '<p>Old</p>', excerpt: '', status: 'draft', company_id: 3, author: 1, categories: [], types: [], locations: [], skills: [], salary_min: null, salary_max: null, salary_currency: 'USD', salary_period: 'year', url: '/job/designer/', counts: {} },
			'admin/ai/describe': { title: 'Senior Product Designer', excerpt: 'Design our app.', content: '<p>New role.</p>\n<h3>What you will do</h3>\n<ul>\n<li>Design flows</li>\n</ul>', skills: [ 'Figma' ], experience: 'senior', source: 'template', meta: {} },
		} );
		const view = await render( withApp( <JobEditor id="5" /> ) );
		await view.flush();
		expect( view.$( 'input[value="Designer"]' ) ).toBeTruthy();
		await click( view.getByText( 'Build outline' ) );
		await view.flush();
		expect( view.getByText( 'Outline from your notes' ) ).toBeTruthy();
		await click( view.getByText( 'Use this draft' ) );
		await view.flush();
		expect( view.$( 'input[value="Senior Product Designer"]' ) ).toBeTruthy();
		expect( view.$$( 'textarea' ).some( ( t ) => t.value.includes( '- Design flows' ) ) ).toBe( true );
		view.unmount();
	} );
} );
