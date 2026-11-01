import { createContext, useContext } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Badge } from '../core3/ui';

/** Shared admin data (meta vocabulary + settings) for every screen. */
export const AppContext = createContext( { meta: null, settings: null, reloadMeta: () => {}, save: () => Promise.resolve(), onUsage: () => {} } );
export const useApp = () => useContext( AppContext );

export const JOB_STATUS = {
	publish: { label: __( 'Live', 'dev3-ai-job-board' ), tone: 'success' },
	pending: { label: __( 'In review', 'dev3-ai-job-board' ), tone: 'warning' },
	draft: { label: __( 'Draft', 'dev3-ai-job-board' ), tone: 'neutral' },
	dev3jb_unpaid: { label: __( 'Awaiting payment', 'dev3-ai-job-board' ), tone: 'info' },
	dev3jb_expired: { label: __( 'Expired', 'dev3-ai-job-board' ), tone: 'danger' },
	trash: { label: __( 'Trash', 'dev3-ai-job-board' ), tone: 'neutral' },
};

export const STAGES = [ 'new', 'screening', 'interview', 'offer', 'hired', 'rejected' ];

export const STAGE_TONE = {
	new: 'info',
	screening: 'brand',
	interview: 'warning',
	offer: 'success',
	hired: 'success',
	rejected: 'neutral',
	withdrawn: 'neutral',
};

/**
 * Job status badge.
 *
 * @param {Object} props        Props.
 * @param {string} props.status Status.
 * @return {Element} Badge.
 */
export function StatusBadge( { status } ) {
	const s = JOB_STATUS[ status ] || { label: status, tone: 'neutral' };
	return (
		<Badge tone={ s.tone } dot>
			{ s.label }
		</Badge>
	);
}

/**
 * Stage badge.
 *
 * @param {Object} props        Props.
 * @param {string} props.stage  Stage.
 * @param {Object} props.labels Labels.
 * @return {Element} Badge.
 */
export function StageBadge( { stage, labels } ) {
	return <Badge tone={ STAGE_TONE[ stage ] || 'neutral' }>{ ( labels && labels[ stage ] ) || stage }</Badge>;
}

/**
 * Score tone.
 *
 * @param {number|null} score Score.
 * @return {string} Tone.
 */
export const scoreTone = ( score ) => {
	if ( score === null || score === undefined ) {
		return 'neutral';
	}
	if ( score >= 75 ) {
		return 'success';
	}
	return score >= 50 ? 'warning' : 'neutral';
};

/**
 * Match score badge.
 *
 * @param {Object}      props       Props.
 * @param {number|null} props.score Score.
 * @return {Element|null} Badge.
 */
export function ScoreBadge( { score } ) {
	if ( score === null || score === undefined ) {
		return null;
	}
	return (
		/* translators: %d: score 0-100 */
		<Badge tone={ scoreTone( score ) }>{ sprintf( __( '%d%% match', 'dev3-ai-job-board' ), score ) }</Badge>
	);
}

/**
 * Localised date.
 *
 * @param {string}  value    MySQL GMT date or ISO.
 * @param {boolean} withTime Include time.
 * @return {string} Date.
 */
export function formatDate( value, withTime = false ) {
	if ( ! value ) {
		return '—';
	}
	const iso = /T/.test( value ) ? value : String( value ).replace( ' ', 'T' ) + ( /^\d{4}-\d{2}-\d{2}$/.test( value ) ? '' : 'Z' );
	const d = new Date( iso );
	if ( isNaN( d.getTime() ) ) {
		return value;
	}
	return withTime ? d.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } ) : d.toLocaleDateString( undefined, { dateStyle: 'medium' } );
}

/**
 * Money.
 *
 * @param {number} amount   Amount.
 * @param {string} currency ISO.
 * @return {string} Money.
 */
export function money( amount, currency = 'USD' ) {
	try {
		return new Intl.NumberFormat( undefined, { style: 'currency', currency, maximumFractionDigits: Number( amount ) % 1 ? 2 : 0 } ).format( Number( amount ) || 0 );
	} catch ( e ) {
		return `${ currency } ${ amount }`;
	}
}

/**
 * Options for a Select from a term list.
 *
 * @param {Array}  terms Terms.
 * @param {string} empty Empty label.
 * @return {Array} Options.
 */
export function termOptions( terms, empty ) {
	const byParent = {};
	( terms || [] ).forEach( ( t ) => {
		( byParent[ t.parent ] = byParent[ t.parent ] || [] ).push( t );
	} );
	const out = empty ? [ { value: '', label: empty } ] : [];
	const walk = ( parent, depth ) =>
		( byParent[ parent ] || [] ).forEach( ( t ) => {
			out.push( { value: String( t.id ), label: '— '.repeat( depth ) + t.name } );
			walk( t.id, depth + 1 );
		} );
	walk( 0, 0 );
	return out;
}

/**
 * Initials for avatars.
 *
 * @param {string} name Name.
 * @return {string} Initials.
 */
export function initials( name ) {
	return (
		String( name || '?' )
			.trim()
			.split( /\s+/ )
			.slice( 0, 2 )
			.map( ( w ) => w.charAt( 0 ).toUpperCase() )
			.join( '' ) || '?'
	);
}

/**
 * Applies accepted inclusive-language fixes to HTML (case-insensitive, keeps a leading capital).
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
 * Plain text from HTML (for previews).
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function stripTags( html ) {
	return String( html || '' )
		.replace( /<\/(p|li|h3|h4)>/gi, '\n' )
		.replace( /<[^>]+>/g, '' )
		.replace( /&amp;/g, '&' )
		.replace( /&#0?39;|&#8217;/g, "'" )
		.replace( /&quot;/g, '"' )
		.replace( /\n{3,}/g, '\n\n' )
		.trim();
}
