<?php
/**
 * Front-end markup: job board, job cards, single job, apply box, companies.
 *
 * Every template is filterable (`dev3jb_template_<name>`), uses prefixed classes and inherits the
 * theme's fonts. Colours come from CSS custom properties on `.dev3jb`.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Data\Applications;
use Dev3\AiJobBoard\Data\Companies;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Resumes;
use Dev3\AiJobBoard\Data\Saved;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static templates.
 */
class Templates {

	/**
	 * Inline SVG icon.
	 *
	 * @param string $name Icon.
	 * @param int    $size Size.
	 * @return string
	 */
	public static function icon( $name, $size = 18 ) {
		$paths = array(
			'pin'       => 'M12 21s-7-6.3-7-11.5A7 7 0 0119 9.5C19 14.7 12 21 12 21zm0-9a2.5 2.5 0 100-5 2.5 2.5 0 000 5z',
			'briefcase' => 'M3 7h18v13H3zM8 7V4h8v3M3 13h18',
			'clock'     => 'M12 22a10 10 0 100-20 10 10 0 000 20zM12 6v6l4 2',
			'money'     => 'M2 6h20v12H2zM12 15a3 3 0 100-6 3 3 0 000 6zM6 12h.01M18 12h.01',
			'home'      => 'M3 11l9-8 9 8v10H3zM9 21v-6h6v6',
			'layers'    => 'M12 2l10 6-10 6L2 8l10-6zM2 16l10 6 10-6',
			'heart'     => 'M20.8 4.6a5.5 5.5 0 00-7.8 0L12 5.7l-1-1a5.5 5.5 0 00-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 000-7.8z',
			'search'    => 'M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.3-4.3',
			'bell'      => 'M18 8a6 6 0 00-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 01-3.4 0',
			'sparkles'  => 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3z',
			'check'     => 'M20 6L9 17l-5-5',
			'x'         => 'M18 6L6 18M6 6l12 12',
			'globe'     => 'M12 22a10 10 0 100-20 10 10 0 000 20zM2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z',
			'mail'      => 'M4 4h16v16H4zM22 6l-10 7L2 6',
			'external'  => 'M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3',
			'calendar'  => 'M3 4h18v18H3zM16 2v4M8 2v4M3 10h18',
			'file'      => 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8zM14 2v6h6',
			'upload'    => 'M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12',
			'users'     => 'M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8',
			'star'      => 'M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z',
			'plus'      => 'M12 5v14M5 12h14',
			'trash'     => 'M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6',
			'edit'      => 'M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z',
			'filter'    => 'M22 3H2l8 9.5V19l4 2v-8.5L22 3z',
			'arrow'     => 'M5 12h14M12 5l7 7-7 7',
			'back'      => 'M19 12H5M12 19l-7-7 7-7',
			'eye'       => 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 15a3 3 0 100-6 3 3 0 000 6z',
			'building'  => 'M3 21h18M5 21V5l7-3v19M19 21V9l-7-3M9 9h.01M9 13h.01M9 17h.01M15 13h.01M15 17h.01',
		);
		$d     = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['briefcase'];
		return '<svg class="dev3jb-icon" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr( $d ) . '"/></svg>';
	}

	/**
	 * Company logo or initials.
	 *
	 * @param array|null $company Company summary.
	 * @param string     $size    sm|md|lg.
	 * @return string
	 */
	public static function logo( $company, $size = 'md' ) {
		$cls = 'dev3jb-logo dev3jb-logo--' . $size;
		if ( $company && $company['logo'] ) {
			return '<span class="' . esc_attr( $cls ) . '"><img src="' . esc_url( $company['logo'] ) . '" alt="" width="64" height="64" loading="lazy" decoding="async"></span>';
		}
		$name = $company ? $company['name'] : get_bloginfo( 'name' );
		$hue  = abs( crc32( (string) $name ) ) % 360;
		return '<span class="' . esc_attr( $cls ) . ' dev3jb-logo--initials" style="--dev3jb-hue:' . (int) $hue . '" aria-hidden="true">' . esc_html( Companies::initials( $name ) ) . '</span>';
	}

	/**
	 * "3 days ago".
	 *
	 * @param string $gmt GMT datetime.
	 * @return string
	 */
	public static function ago( $gmt ) {
		$ts = strtotime( $gmt . ' UTC' );
		if ( ! $ts ) {
			return '';
		}
		if ( time() - $ts < DAY_IN_SECONDS ) {
			return __( 'Today', 'dev3-ai-job-board' );
		}
		/* translators: %s: human time difference */
		return sprintf( __( '%s ago', 'dev3-ai-job-board' ), human_time_diff( $ts, time() ) );
	}

	/**
	 * Meta chips of a job.
	 *
	 * @param array $job  Job.
	 * @param bool  $full Include experience/deadline.
	 * @return array [ [ icon, text, class ] ]
	 */
	public static function chips( array $job, $full = false ) {
		$wp     = Listings::workplace_labels();
		$chips  = array();
		$places = wp_list_pluck( $job['locations'], 'name' );
		if ( 'remote' === $job['workplace'] ) {
			$chips[] = array( 'globe', $job['remote_region'] ? $wp['remote'] . ' · ' . $job['remote_region'] : $wp['remote'], 'remote' );
		} else {
			if ( $places ) {
				$chips[] = array( 'pin', implode( ', ', $places ), '' );
			}
			if ( 'hybrid' === $job['workplace'] ) {
				$chips[] = array( 'home', $wp['hybrid'], 'hybrid' );
			}
		}
		foreach ( $job['types'] as $t ) {
			$chips[] = array( 'briefcase', $t['name'], 'type' );
		}
		if ( '' !== $job['salary_text'] ) {
			$chips[] = array( 'money', $job['salary_text'], 'salary' );
		}
		if ( $full ) {
			$levels = Listings::experience_labels();
			if ( isset( $levels[ $job['experience'] ] ) ) {
				$chips[] = array( 'layers', $levels[ $job['experience'] ], '' );
			}
			if ( $job['deadline'] ) {
				/* translators: %s: date */
				$chips[] = array( 'calendar', sprintf( __( 'Apply by %s', 'dev3-ai-job-board' ), mysql2date( get_option( 'date_format' ), $job['deadline'] ) ), '' );
			}
		}
		return $chips;
	}

	/**
	 * Chip list HTML.
	 *
	 * @param array $chips Chips.
	 * @return string
	 */
	public static function chip_list( array $chips ) {
		$html = '<ul class="dev3jb-chips">';
		foreach ( $chips as $c ) {
			$html .= '<li class="dev3jb-chip' . ( $c[2] ? ' dev3jb-chip--' . esc_attr( $c[2] ) : '' ) . '">' . self::icon( $c[0], 15 ) . '<span>' . esc_html( $c[1] ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Save-job button.
	 *
	 * @param int $job_id Job.
	 * @return string
	 */
	public static function save_button( $job_id ) {
		$user = get_current_user_id();
		if ( ! $user ) {
			return '<a class="dev3jb-iconbtn" href="' . esc_url( wp_login_url( (string) get_permalink( $job_id ) ) ) . '" aria-label="' . esc_attr__( 'Log in to save this job', 'dev3-ai-job-board' ) . '">' . self::icon( 'heart' ) . '</a>';
		}
		$saved = in_array( (int) $job_id, Saved::ids( $user ), true );
		return '<button type="button" class="dev3jb-iconbtn" data-dev3jb-save="' . (int) $job_id . '" aria-pressed="' . ( $saved ? 'true' : 'false' ) . '" aria-label="' . esc_attr__( 'Save job', 'dev3-ai-job-board' ) . '">' . self::icon( 'heart' ) . '</button>';
	}

	/**
	 * Job card (list item).
	 *
	 * @param array $job  Job.
	 * @param array $opts { reasons[], score, compact }.
	 * @return string
	 */
	public static function job_card( array $job, array $opts = array() ) {
		$classes = 'dev3jb-job' . ( $job['featured'] ? ' is-featured' : '' ) . ( $job['filled'] ? ' is-filled' : '' );
		$company = $job['company'];
		$html    = '<li class="' . esc_attr( $classes ) . '">';
		$html   .= self::logo( $company );
		$html   .= '<div class="dev3jb-job__main">';
		$html   .= '<h3 class="dev3jb-job__title"><a href="' . esc_url( $job['url'] ) . '">' . esc_html( $job['title'] ) . '</a></h3>';
		$html   .= '<p class="dev3jb-job__company">' . esc_html( $company ? $company['name'] : get_bloginfo( 'name' ) ) . '</p>';
		$html   .= self::chip_list( self::chips( $job ) );
		if ( ! empty( $opts['reasons'] ) ) {
			/* translators: %s: skills */
			$html .= '<p class="dev3jb-job__why">' . self::icon( 'sparkles', 15 ) . ' ' . esc_html( sprintf( __( 'Matches your skills: %s', 'dev3-ai-job-board' ), implode( ', ', $opts['reasons'] ) ) ) . '</p>';
		}
		$html .= '</div><div class="dev3jb-job__side">';
		if ( $job['featured'] ) {
			$html .= '<span class="dev3jb-tag dev3jb-tag--featured">' . esc_html__( 'Featured', 'dev3-ai-job-board' ) . '</span>';
		}
		if ( $job['filled'] ) {
			$html .= '<span class="dev3jb-tag">' . esc_html__( 'Filled', 'dev3-ai-job-board' ) . '</span>';
		}
		$html .= '<span class="dev3jb-job__date">' . esc_html( self::ago( $job['date'] ) ) . '</span>';
		if ( empty( $opts['compact'] ) ) {
			$html .= self::save_button( $job['id'] );
		}
		$html .= '</div></li>';
		/**
		 * Filters a job card.
		 *
		 * @param string $html Card.
		 * @param array  $job  Job.
		 */
		return apply_filters( 'dev3jb_template_job_card', $html, $job );
	}

	/**
	 * Allowed GET filters of the board.
	 *
	 * @param array $atts Shortcode defaults.
	 * @return array
	 */
	public static function request_args( array $atts = array() ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only filters.
		$get  = static function ( $key ) {
			if ( ! isset( $_GET[ $key ] ) ) {
				return '';
			}
			$v = wp_unslash( $_GET[ $key ] );
			return is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( $v );
		};
		$args = array(
			'q'          => mb_substr( (string) $get( 'q' ), 0, 120 ),
			'category'   => $get( 'category' ),
			'type'       => $get( 'type' ),
			'location'   => $get( 'location' ),
			'workplace'  => $get( 'workplace' ),
			'salary_min' => (int) $get( 'salary_min' ),
			'posted'     => (int) $get( 'posted' ),
			'orderby'    => in_array( $get( 'sort' ), array( 'date', 'salary' ), true ) ? $get( 'sort' ) : 'date',
			'page'       => max( 1, (int) $get( 'pg' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'category', 'type', 'location', 'workplace' ) as $k ) {
			if ( '' === $args[ $k ] && ! empty( $atts[ $k ] ) ) {
				$args[ $k ] = sanitize_text_field( (string) $atts[ $k ] );
			}
		}
		return $args;
	}

	/**
	 * Select of terms.
	 *
	 * @param string $name     Field name.
	 * @param string $taxonomy Taxonomy.
	 * @param string $current  Current slug.
	 * @param string $all      "All" label.
	 * @param string $id       Element id.
	 * @return string
	 */
	public static function term_select( $name, $taxonomy, $current, $all, $id ) {
		$html = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '"><option value="">' . esc_html( $all ) . '</option>';
		$by   = array();
		foreach ( Post_Types::terms( $taxonomy ) as $t ) {
			$by[ $t['parent'] ][] = $t;
		}
		$walk = static function ( $parent_id, $depth ) use ( &$walk, $by, $current, &$html ) {
			foreach ( isset( $by[ $parent_id ] ) ? $by[ $parent_id ] : array() as $t ) {
				$html .= '<option value="' . esc_attr( $t['slug'] ) . '"' . selected( $current, $t['slug'], false ) . '>' . esc_html( str_repeat( '— ', $depth ) . $t['name'] ) . '</option>';
				$walk( $t['id'], $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $html . '</select>';
	}

	/**
	 * The job board: search form, filters, results.
	 *
	 * @param array $atts { per_page, filters (yes|no), category, type, location, workplace }.
	 * @return string
	 */
	public static function board( array $atts ) {
		$args             = self::request_args( $atts );
		$per_page         = ! empty( $atts['per_page'] ) ? min( 50, max( 1, (int) $atts['per_page'] ) ) : (int) Settings::get( 'per_page', 10 );
		$args['per_page'] = $per_page;
		$filters          = ! isset( $atts['filters'] ) || 'no' !== $atts['filters'];
		$result           = Listings::search( $args + array( 'featured_first' => 'date' === $args['orderby'] ) );
		$uid              = 'dev3jb-b' . wp_rand( 100, 999 );
		$wp               = Listings::workplace_labels();
		$cfg              = array(
			'per_page' => $per_page,
			'base'     => (string) get_permalink(),
			'fixed'    => array_filter( array_intersect_key( $atts, array_flip( array( 'category', 'type', 'location', 'workplace' ) ) ) ),
		);
		$html             = '<div class="dev3jb dev3jb-board" data-dev3jb-board="' . esc_attr( (string) wp_json_encode( $cfg ) ) . '">';
		$html            .= Forms::notices(
			array(
				'alert_confirmed'    => __( 'Your job alert is confirmed. We will email you when new jobs match.', 'dev3-ai-job-board' ),
				'alert_unsubscribed' => __( 'You are unsubscribed from that job alert.', 'dev3-ai-job-board' ),
			)
		);
		$html            .= '<form class="dev3jb-search" role="search" method="get" action="' . esc_url( (string) get_permalink() ) . '" data-dev3jb-search>';
		$html            .= '<div class="dev3jb-search__main">';
		$html            .= '<div class="dev3jb-field dev3jb-field--grow"><label for="' . esc_attr( $uid ) . '-q">' . esc_html__( 'Keywords', 'dev3-ai-job-board' ) . '</label><span class="dev3jb-inputicon">' . self::icon( 'search' ) . '<input type="search" id="' . esc_attr( $uid ) . '-q" name="q" value="' . esc_attr( $args['q'] ) . '" placeholder="' . esc_attr__( 'Job title, skill or company', 'dev3-ai-job-board' ) . '"></span></div>';
		$html            .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-loc">' . esc_html__( 'Location', 'dev3-ai-job-board' ) . '</label>' . self::term_select( 'location', Post_Types::LOCATION, is_array( $args['location'] ) ? '' : $args['location'], __( 'Anywhere', 'dev3-ai-job-board' ), $uid . '-loc' ) . '</div>';
		$html            .= '<button type="submit" class="dev3jb-btn dev3jb-btn--primary dev3jb-search__go">' . esc_html__( 'Search jobs', 'dev3-ai-job-board' ) . '</button>';
		$html            .= '</div>';
		if ( $filters ) {
			$html .= '<details class="dev3jb-filters"' . ( ( $args['category'] || $args['type'] || $args['workplace'] || $args['salary_min'] || $args['posted'] ) ? ' open' : '' ) . '><summary>' . self::icon( 'filter', 16 ) . ' ' . esc_html__( 'Filters', 'dev3-ai-job-board' ) . '</summary><div class="dev3jb-filters__grid">';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-cat">' . esc_html__( 'Category', 'dev3-ai-job-board' ) . '</label>' . self::term_select( 'category', Post_Types::CATEGORY, is_array( $args['category'] ) ? '' : $args['category'], __( 'All categories', 'dev3-ai-job-board' ), $uid . '-cat' ) . '</div>';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-type">' . esc_html__( 'Job type', 'dev3-ai-job-board' ) . '</label>' . self::term_select( 'type', Post_Types::TYPE, is_array( $args['type'] ) ? '' : $args['type'], __( 'Any type', 'dev3-ai-job-board' ), $uid . '-type' ) . '</div>';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-wp">' . esc_html__( 'Workplace', 'dev3-ai-job-board' ) . '</label><select id="' . esc_attr( $uid ) . '-wp" name="workplace"><option value="">' . esc_html__( 'Any workplace', 'dev3-ai-job-board' ) . '</option>';
			foreach ( $wp as $k => $label ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $args['workplace'], $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></div>';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-sal">' . esc_html__( 'Minimum salary', 'dev3-ai-job-board' ) . '</label><select id="' . esc_attr( $uid ) . '-sal" name="salary_min"><option value="">' . esc_html__( 'Any salary', 'dev3-ai-job-board' ) . '</option>';
			foreach ( array( 30000, 50000, 75000, 100000, 150000 ) as $amount ) {
				$html .= '<option value="' . (int) $amount . '"' . selected( $args['salary_min'], $amount, false ) . '>' . esc_html( Listings::money( $amount ) . '+' ) . '</option>';
			}
			$html .= '</select></div>';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-posted">' . esc_html__( 'Date posted', 'dev3-ai-job-board' ) . '</label><select id="' . esc_attr( $uid ) . '-posted" name="posted"><option value="">' . esc_html__( 'Any time', 'dev3-ai-job-board' ) . '</option>';
			foreach ( array(
				1  => __( 'Last 24 hours', 'dev3-ai-job-board' ),
				7  => __( 'Last 7 days', 'dev3-ai-job-board' ),
				30 => __( 'Last 30 days', 'dev3-ai-job-board' ),
			) as $days => $label ) {
				$html .= '<option value="' . (int) $days . '"' . selected( $args['posted'], $days, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$html .= '</select></div>';
			$html .= '</div><p class="dev3jb-filters__foot"><a class="dev3jb-link" href="' . esc_url( (string) get_permalink() ) . '" data-dev3jb-reset>' . esc_html__( 'Clear filters', 'dev3-ai-job-board' ) . '</a></p></details>';
		}
		$html .= '</form>';
		$html .= '<div class="dev3jb-results" data-dev3jb-results aria-live="polite">' . self::results_html( $result, $args ) . '</div>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * Results header + list + pagination.
	 *
	 * @param array $result Search result.
	 * @param array $args   Args.
	 * @return string
	 */
	public static function results_html( array $result, array $args ) {
		$html = '<div class="dev3jb-results__head">';
		/* translators: %s: number of jobs */
		$html .= '<p class="dev3jb-count" data-dev3jb-count>' . esc_html( sprintf( _n( '%s job', '%s jobs', $result['total'], 'dev3-ai-job-board' ), number_format_i18n( $result['total'] ) ) ) . '</p>';
		$html .= '<div class="dev3jb-results__tools">';
		if ( Settings::get( 'alerts_enabled', true ) ) {
			$html .= '<button type="button" class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" data-dev3jb-alert-open>' . self::icon( 'bell', 16 ) . esc_html__( 'Email me jobs like these', 'dev3-ai-job-board' ) . '</button>';
		}
		$html .= '<label class="dev3jb-sr" for="dev3jb-sort">' . esc_html__( 'Sort by', 'dev3-ai-job-board' ) . '</label><select id="dev3jb-sort" class="dev3jb-sort" data-dev3jb-sort><option value="date"' . selected( $args['orderby'], 'date', false ) . '>' . esc_html__( 'Newest first', 'dev3-ai-job-board' ) . '</option><option value="salary"' . selected( $args['orderby'], 'salary', false ) . '>' . esc_html__( 'Highest salary', 'dev3-ai-job-board' ) . '</option></select>';
		$html .= '</div></div>';
		if ( ! $result['items'] ) {
			$html .= '<div class="dev3jb-empty">' . self::icon( 'search', 28 ) . '<h3>' . esc_html__( 'No jobs match your search', 'dev3-ai-job-board' ) . '</h3><p>' . esc_html__( 'Try fewer filters or a different keyword. You can also create an alert and we will email you when a match is posted.', 'dev3-ai-job-board' ) . '</p></div>';
			return $html;
		}
		$html .= '<ul class="dev3jb-list">';
		foreach ( $result['items'] as $job ) {
			$html .= self::job_card( $job );
		}
		$html .= '</ul>';
		$html .= self::pagination( (int) $result['page'], (int) $result['pages'], $args );
		return $html;
	}

	/**
	 * Pagination links (?pg=N keeps the other filters).
	 *
	 * @param int   $page  Current.
	 * @param int   $pages Total.
	 * @param array $args  Args.
	 * @return string
	 */
	public static function pagination( $page, $pages, array $args ) {
		if ( $pages < 2 ) {
			return '';
		}
		$base = array_filter(
			array(
				'q'          => $args['q'],
				'category'   => is_array( $args['category'] ) ? implode( ',', $args['category'] ) : $args['category'],
				'type'       => is_array( $args['type'] ) ? implode( ',', $args['type'] ) : $args['type'],
				'location'   => is_array( $args['location'] ) ? implode( ',', $args['location'] ) : $args['location'],
				'workplace'  => is_array( $args['workplace'] ) ? implode( ',', $args['workplace'] ) : $args['workplace'],
				'salary_min' => $args['salary_min'],
				'posted'     => $args['posted'],
				'sort'       => 'date' === $args['orderby'] ? '' : $args['orderby'],
			)
		);
		$url  = isset( $args['base'] ) ? $args['base'] : (string) get_permalink();
		$html = '<nav class="dev3jb-pager" aria-label="' . esc_attr__( 'Job pages', 'dev3-ai-job-board' ) . '">';
		if ( $page > 1 ) {
			$html .= '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" data-dev3jb-page="' . ( $page - 1 ) . '" href="' . esc_url( add_query_arg( $base + array( 'pg' => $page - 1 ), $url ) ) . '">' . esc_html__( 'Previous', 'dev3-ai-job-board' ) . '</a>';
		}
		/* translators: 1: page, 2: pages */
		$html .= '<span class="dev3jb-pager__info">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'dev3-ai-job-board' ), $page, $pages ) ) . '</span>';
		if ( $page < $pages ) {
			$html .= '<a class="dev3jb-btn dev3jb-btn--ghost dev3jb-btn--sm" data-dev3jb-page="' . ( $page + 1 ) . '" href="' . esc_url( add_query_arg( $base + array( 'pg' => $page + 1 ), $url ) ) . '">' . esc_html__( 'Next', 'dev3-ai-job-board' ) . '</a>';
		}
		return $html . '</nav>';
	}

	/**
	 * Single job page body.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function single_job( array $job ) {
		$company = $job['company'];
		$html    = '<div class="dev3jb dev3jb-single">';
		$html   .= Forms::notices(
			array(
				'applied' => __( 'Your application was sent. Good luck!', 'dev3-ai-job-board' ),
			)
		);
		if ( 'publish' !== $job['status'] ) {
			$labels = array(
				'pending'           => __( 'This job is waiting for review and is not public yet.', 'dev3-ai-job-board' ),
				'draft'             => __( 'Preview: this job is a draft and is not public yet.', 'dev3-ai-job-board' ),
				Post_Types::UNPAID  => __( 'This job is waiting for payment and is not public yet.', 'dev3-ai-job-board' ),
				Post_Types::EXPIRED => __( 'This job has expired.', 'dev3-ai-job-board' ),
			);
			$html  .= '<div class="dev3jb-notice dev3jb-notice--info" role="status">' . esc_html( isset( $labels[ $job['status'] ] ) ? $labels[ $job['status'] ] : $labels['draft'] ) . '</div>';
		}
		$html .= '<header class="dev3jb-single__head">' . self::logo( $company, 'lg' ) . '<div class="dev3jb-single__titles">';
		if ( $company ) {
			$html .= '<p class="dev3jb-single__company">' . ( $company['url'] ? '<a href="' . esc_url( $company['url'] ) . '">' . esc_html( $company['name'] ) . '</a>' : esc_html( $company['name'] ) ) . '</p>';
		}
		$html .= '<p class="dev3jb-single__posted">' . self::icon( 'clock', 15 ) . ' ' . esc_html(
			sprintf(
				/* translators: %s: time ago */
				__( 'Posted %s', 'dev3-ai-job-board' ),
				mb_strtolower( self::ago( $job['date'] ) )
			)
		) . ( $job['featured'] ? ' <span class="dev3jb-tag dev3jb-tag--featured">' . esc_html__( 'Featured', 'dev3-ai-job-board' ) . '</span>' : '' ) . ( $job['filled'] ? ' <span class="dev3jb-tag">' . esc_html__( 'Position filled', 'dev3-ai-job-board' ) . '</span>' : '' ) . '</p>';
		$html .= self::chip_list( self::chips( $job, true ) ) . '</div>';
		$html .= '<div class="dev3jb-single__actions"><a class="dev3jb-btn dev3jb-btn--primary" href="#dev3jb-apply">' . esc_html__( 'Apply now', 'dev3-ai-job-board' ) . '</a>' . self::save_button( $job['id'] ) . '</div></header>';
		$html .= '<div class="dev3jb-single__grid"><div class="dev3jb-single__body">';
		$html .= '<div class="dev3jb-prose">' . wp_kses_post( wpautop( $job['content'] ) ) . '</div>';
		if ( $job['skills'] ) {
			$html .= '<h2 class="dev3jb-h">' . esc_html__( 'Skills', 'dev3-ai-job-board' ) . '</h2><ul class="dev3jb-skills">';
			foreach ( $job['skills'] as $s ) {
				$html .= '<li>' . esc_html( $s['name'] ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div><aside class="dev3jb-single__aside">' . self::apply_box( $job );
		if ( $company ) {
			$full  = Companies::get( $company['id'] );
			$html .= '<section class="dev3jb-box dev3jb-companybox" aria-labelledby="dev3jb-cb-' . (int) $company['id'] . '"><div class="dev3jb-companybox__head">' . self::logo( $company, 'sm' ) . '<h2 class="dev3jb-h" id="dev3jb-cb-' . (int) $company['id'] . '">' . esc_html( $company['name'] ) . '</h2></div>';
			if ( $full && '' !== $full['tagline'] ) {
				$html .= '<p class="dev3jb-muted">' . esc_html( $full['tagline'] ) . '</p>';
			}
			$facts = array();
			if ( $full && $full['industry'] ) {
				$facts[] = $full['industry'];
			}
			if ( $full && $full['size'] ) {
				/* translators: %s: company size */
				$facts[] = sprintf( __( '%s people', 'dev3-ai-job-board' ), $full['size'] );
			}
			if ( $full && $full['headquarters'] ) {
				$facts[] = $full['headquarters'];
			}
			if ( $facts ) {
				$html .= '<p class="dev3jb-companybox__facts">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
			}
			$html .= '<p class="dev3jb-companybox__links">';
			if ( $company['url'] ) {
				/* translators: %d: number of open jobs */
				$html .= '<a class="dev3jb-link" href="' . esc_url( $company['url'] ) . '">' . esc_html( sprintf( _n( '%d open job', '%d open jobs', $full ? $full['jobs'] : 0, 'dev3-ai-job-board' ), $full ? $full['jobs'] : 0 ) ) . '</a>';
			}
			if ( $company['website'] ) {
				$html .= '<a class="dev3jb-link" href="' . esc_url( $company['website'] ) . '" rel="noopener nofollow" target="_blank">' . esc_html__( 'Website', 'dev3-ai-job-board' ) . self::icon( 'external', 14 ) . '</a>';
			}
			$html .= '</p></section>';
		}
		$html   .= '</aside></div>';
		$related = self::related( $job );
		if ( $related ) {
			$html .= '<section class="dev3jb-related" aria-labelledby="dev3jb-related-h"><h2 class="dev3jb-h" id="dev3jb-related-h">' . esc_html__( 'Similar jobs', 'dev3-ai-job-board' ) . '</h2><ul class="dev3jb-list">';
			foreach ( $related as $r ) {
				$html .= self::job_card( $r, array( 'compact' => true ) );
			}
			$html .= '</ul></section>';
		}
		$html .= '</div>';
		/**
		 * Filters the single job markup.
		 *
		 * @param string $html Markup.
		 * @param array  $job  Job.
		 */
		return apply_filters( 'dev3jb_template_single_job', $html, $job );
	}

	/**
	 * Related open jobs (same category).
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function related( array $job ) {
		if ( ! $job['categories'] ) {
			return array();
		}
		$res = Listings::search(
			array(
				'category' => wp_list_pluck( $job['categories'], 'slug' ),
				'per_page' => 4,
			)
		);
		$out = array();
		foreach ( $res['items'] as $r ) {
			if ( $r['id'] !== $job['id'] && count( $out ) < 3 ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Apply box (internal form, external URL, email, closed, or the employer's own view).
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function apply_box( array $job ) {
		$user = get_current_user_id();
		$html = '<section class="dev3jb-box dev3jb-apply" id="dev3jb-apply" aria-labelledby="dev3jb-apply-h"><h2 class="dev3jb-h" id="dev3jb-apply-h">' . esc_html__( 'Apply for this job', 'dev3-ai-job-board' ) . '</h2>';
		if ( $user && Listings::owner_can( $job['id'], $user ) && (int) $job['author'] === $user ) {
			/* translators: %d: number of applications */
			$html .= '<p>' . esc_html( sprintf( _n( 'You posted this job. It has %d application.', 'You posted this job. It has %d applications.', $job['applications'], 'dev3-ai-job-board' ), $job['applications'] ) ) . '</p><p><a class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--block" href="' . esc_url( add_query_arg( array( 'dev3jb_tab' => 'applications', 'job' => $job['id'] ), Settings::page_url( 'employer' ) ) ) . '">' . esc_html__( 'Review applications', 'dev3-ai-job-board' ) . '</a></p></section>'; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			return $html;
		}
		$open = Applications::can_apply( $job );
		if ( is_wp_error( $open ) && ! in_array( $open->get_error_code(), array( 'dev3jb_external' ), true ) ) {
			return $html . '<p class="dev3jb-muted">' . esc_html( $open->get_error_message() ) . '</p></section>';
		}
		if ( 'url' === $job['apply_method'] && $job['apply_url'] ) {
			return $html . '<p class="dev3jb-muted">' . esc_html__( 'This employer takes applications on their own website.', 'dev3-ai-job-board' ) . '</p><p><a class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--block" href="' . esc_url( $job['apply_url'] ) . '" rel="noopener nofollow" target="_blank">' . esc_html__( 'Apply on company site', 'dev3-ai-job-board' ) . self::icon( 'external', 16 ) . '</a></p></section>';
		}
		if ( 'email' === $job['apply_method'] && $job['apply_email'] ) {
			/* translators: %s: job title */
			$subject = rawurlencode( sprintf( __( 'Application: %s', 'dev3-ai-job-board' ), $job['title'] ) );
			return $html . '<p class="dev3jb-muted">' . esc_html__( 'Send your resume and a short note by email.', 'dev3-ai-job-board' ) . '</p><p><a class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--block" href="mailto:' . esc_attr( antispambot( $job['apply_email'] ) ) . '?subject=' . esc_attr( $subject ) . '">' . self::icon( 'mail', 16 ) . esc_html__( 'Apply by email', 'dev3-ai-job-board' ) . '</a></p></section>';
		}
		$applied = false;
		if ( $user ) {
			$mine    = Applications::query(
				array(
					'job_id'            => $job['id'],
					'candidate_id'      => $user,
					'per_page'          => 1,
					'include_withdrawn' => true,
				)
			);
			$applied = $mine['total'] > 0;
		}
		if ( $applied ) {
			return $html . '<p class="dev3jb-notice dev3jb-notice--ok">' . esc_html__( 'You have applied for this job.', 'dev3-ai-job-board' ) . '</p><p><a class="dev3jb-link" href="' . esc_url( add_query_arg( 'dev3jb_tab', 'applications', Settings::page_url( 'candidate' ) ) ) . '">' . esc_html__( 'Track your applications', 'dev3-ai-job-board' ) . '</a></p></section>';
		}
		if ( ! $user && ! Settings::get( 'guest_apply', true ) ) {
			return $html . '<p class="dev3jb-muted">' . esc_html__( 'Log in or create a free account to apply.', 'dev3-ai-job-board' ) . '</p><p class="dev3jb-row"><a class="dev3jb-btn dev3jb-btn--primary" href="' . esc_url( wp_login_url( $job['url'] ) ) . '">' . esc_html__( 'Log in', 'dev3-ai-job-board' ) . '</a><a class="dev3jb-btn dev3jb-btn--ghost" href="' . esc_url( add_query_arg( 'redirect', rawurlencode( $job['url'] ), Settings::page_url( 'candidate' ) ) ) . '">' . esc_html__( 'Create account', 'dev3-ai-job-board' ) . '</a></p></section>';
		}
		$profile = $user ? Resumes::for_user( $user ) : null;
		$uid     = 'dev3jb-ap-' . (int) $job['id'];
		$max     = (int) Settings::get( 'max_upload_mb', 5 );
		$need    = (bool) Settings::get( 'resume_required', false );
		$html   .= '<form class="dev3jb-form" data-dev3jb-apply="' . (int) $job['id'] . '" novalidate>';
		if ( $profile && $profile['id'] ) {
			$html .= '<div class="dev3jb-applyas">' . self::icon( 'users', 16 ) . '<div><strong>' . esc_html( $profile['name'] ) . '</strong><span>' . esc_html( $profile['headline'] ? $profile['headline'] : $profile['email'] ) . '</span>';
			$html .= $profile['file'] ? '<span>' . self::icon( 'file', 14 ) . ' ' . esc_html( $profile['file']['name'] ) . '</span>' : '';
			$html .= '</div></div><p class="dev3jb-muted dev3jb-small">' . esc_html__( 'Your profile and resume are shared with the employer.', 'dev3-ai-job-board' ) . ' <a class="dev3jb-link" href="' . esc_url( Settings::page_url( 'candidate' ) ) . '">' . esc_html__( 'Edit profile', 'dev3-ai-job-board' ) . '</a></p>';
			$html .= '<input type="hidden" name="name" value="' . esc_attr( $profile['name'] ) . '"><input type="hidden" name="email" value="' . esc_attr( $profile['email'] ) . '">';
			$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-phone">' . esc_html__( 'Phone (optional)', 'dev3-ai-job-board' ) . '</label><input type="tel" id="' . esc_attr( $uid ) . '-phone" name="phone" autocomplete="tel" value="' . esc_attr( $profile['phone'] ) . '"></div>';
		} else {
			$current = $user ? wp_get_current_user() : null;
			$html   .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-name">' . esc_html__( 'Full name', 'dev3-ai-job-board' ) . ' <span aria-hidden="true">*</span></label><input type="text" id="' . esc_attr( $uid ) . '-name" name="name" required autocomplete="name" value="' . esc_attr( $current ? $current->display_name : '' ) . '"></div>';
			$html   .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-email">' . esc_html__( 'Email', 'dev3-ai-job-board' ) . ' <span aria-hidden="true">*</span></label><input type="email" id="' . esc_attr( $uid ) . '-email" name="email" required autocomplete="email" value="' . esc_attr( $current ? $current->user_email : '' ) . '"></div>';
			$html   .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-phone">' . esc_html__( 'Phone (optional)', 'dev3-ai-job-board' ) . '</label><input type="tel" id="' . esc_attr( $uid ) . '-phone" name="phone" autocomplete="tel"></div>';
		}
		$html      .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-cover">' . esc_html__( 'Cover letter (optional)', 'dev3-ai-job-board' ) . '</label><textarea id="' . esc_attr( $uid ) . '-cover" name="cover_letter" rows="5" maxlength="5000" placeholder="' . esc_attr__( 'A few lines about why you are a good fit.', 'dev3-ai-job-board' ) . '"></textarea></div>';
		$file_label = $profile && $profile['file'] ? __( 'Use a different resume (optional)', 'dev3-ai-job-board' ) : ( $need ? __( 'Resume', 'dev3-ai-job-board' ) : __( 'Resume (optional)', 'dev3-ai-job-board' ) );
		/* translators: %d: megabytes */
		$html .= '<div class="dev3jb-field"><label for="' . esc_attr( $uid ) . '-file">' . esc_html( $file_label ) . '</label><input class="dev3jb-file" type="file" id="' . esc_attr( $uid ) . '-file" name="resume" accept=".pdf,.docx,.txt,application/pdf"' . ( $need && ! ( $profile && $profile['file'] ) ? ' required' : '' ) . '><span class="dev3jb-help">' . esc_html( sprintf( __( 'PDF, Word (.docx) or text, up to %d MB.', 'dev3-ai-job-board' ), $max ) ) . '</span></div>';
		$html .= '<label class="dev3jb-check"><input type="checkbox" name="consent" value="1" required> <span>' . esc_html__( 'I agree that my application is shared with this employer.', 'dev3-ai-job-board' ) . '</span></label>';
		$html .= '<div class="dev3jb-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'dev3-ai-job-board' ) . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		$html .= '<p class="dev3jb-form__status" role="status" aria-live="polite" data-dev3jb-status></p>';
		$html .= '<button type="submit" class="dev3jb-btn dev3jb-btn--primary dev3jb-btn--block">' . esc_html__( 'Send application', 'dev3-ai-job-board' ) . '</button>';
		if ( ! $user ) {
			$html .= '<p class="dev3jb-muted dev3jb-small">' . esc_html__( 'Have an account?', 'dev3-ai-job-board' ) . ' <a class="dev3jb-link" href="' . esc_url( wp_login_url( $job['url'] ) ) . '">' . esc_html__( 'Log in to apply with your profile', 'dev3-ai-job-board' ) . '</a></p>';
		}
		$html .= '</form></section>';
		return $html;
	}

	/**
	 * Company page body.
	 *
	 * @param array $company Company.
	 * @return string
	 */
	public static function company_page( array $company ) {
		$html  = '<div class="dev3jb dev3jb-company">';
		$html .= '<header class="dev3jb-single__head">' . self::logo( $company, 'lg' ) . '<div class="dev3jb-single__titles"><p class="dev3jb-single__company">' . esc_html( $company['tagline'] ) . '</p>';
		$facts = array_filter( array( $company['industry'], $company['size'] ? sprintf( /* translators: %s: size */ __( '%s people', 'dev3-ai-job-board' ), $company['size'] ) : '', $company['headquarters'], $company['founded'] ? sprintf( /* translators: %s: year */ __( 'Founded %s', 'dev3-ai-job-board' ), $company['founded'] ) : '' ) );
		if ( $facts ) {
			$html .= '<p class="dev3jb-muted">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
		}
		$html .= '</div>';
		if ( $company['website'] ) {
			$html .= '<div class="dev3jb-single__actions"><a class="dev3jb-btn dev3jb-btn--ghost" href="' . esc_url( $company['website'] ) . '" rel="noopener nofollow" target="_blank">' . self::icon( 'globe', 16 ) . esc_html__( 'Website', 'dev3-ai-job-board' ) . '</a></div>';
		}
		$html .= '</header>';
		if ( '' !== trim( wp_strip_all_tags( $company['description'] ) ) ) {
			$html .= '<div class="dev3jb-prose">' . wp_kses_post( wpautop( $company['description'] ) ) . '</div>';
		}
		$jobs = Listings::search(
			array(
				'company'  => $company['id'],
				'per_page' => 50,
			)
		);
		/* translators: %d: number of jobs */
		$html .= '<h2 class="dev3jb-h">' . esc_html( sprintf( _n( '%d open job', '%d open jobs', $jobs['total'], 'dev3-ai-job-board' ), $jobs['total'] ) ) . '</h2>';
		if ( $jobs['items'] ) {
			$html .= '<ul class="dev3jb-list">';
			foreach ( $jobs['items'] as $job ) {
				$html .= self::job_card( $job );
			}
			$html .= '</ul>';
		} else {
			$html .= '<p class="dev3jb-muted">' . esc_html__( 'No open jobs right now.', 'dev3-ai-job-board' ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Companies grid.
	 *
	 * @param array $atts { limit }.
	 * @return string
	 */
	public static function companies( array $atts ) {
		$limit = ! empty( $atts['limit'] ) ? min( 100, max( 1, (int) $atts['limit'] ) ) : 24;
		$posts = get_posts(
			array(
				'post_type'      => Post_Types::COMPANY,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		if ( ! $posts ) {
			return '<div class="dev3jb dev3jb-empty"><p>' . esc_html__( 'No companies yet.', 'dev3-ai-job-board' ) . '</p></div>';
		}
		$html = '<ul class="dev3jb dev3jb-companies">';
		foreach ( $posts as $post ) {
			$c = Companies::get( $post->ID );
			/* translators: %d: open jobs */
			$html .= '<li class="dev3jb-companytile"><a href="' . esc_url( $c['url'] ) . '">' . self::logo( $c ) . '<span class="dev3jb-companytile__name">' . esc_html( $c['name'] ) . '</span><span class="dev3jb-muted">' . esc_html( sprintf( _n( '%d open job', '%d open jobs', $c['jobs'], 'dev3-ai-job-board' ), $c['jobs'] ) ) . '</span></a></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Recent / featured jobs list.
	 *
	 * @param array $atts { limit, featured (yes|no), category }.
	 * @return string
	 */
	public static function recent( array $atts ) {
		$res = Listings::search(
			array(
				'per_page' => ! empty( $atts['limit'] ) ? min( 20, max( 1, (int) $atts['limit'] ) ) : 5,
				'featured' => isset( $atts['featured'] ) && 'yes' === $atts['featured'],
				'category' => isset( $atts['category'] ) ? (string) $atts['category'] : '',
			)
		);
		if ( ! $res['items'] ) {
			return '';
		}
		$html = '<div class="dev3jb dev3jb-recent"><ul class="dev3jb-list">';
		foreach ( $res['items'] as $job ) {
			$html .= self::job_card( $job, array( 'compact' => true ) );
		}
		return $html . '</ul><p><a class="dev3jb-link" href="' . esc_url( Settings::page_url( 'jobs' ) ) . '">' . esc_html__( 'See all jobs', 'dev3-ai-job-board' ) . ' &rarr;</a></p></div>';
	}

	/**
	 * Compact search box that opens the jobs page.
	 *
	 * @return string
	 */
	public static function search_box() {
		$uid = 'dev3jb-qs-' . wp_rand( 100, 999 );
		return '<form class="dev3jb dev3jb-quicksearch" method="get" role="search" action="' . esc_url( Settings::page_url( 'jobs' ) ) . '"><label class="dev3jb-sr" for="' . esc_attr( $uid ) . '">' . esc_html__( 'Search jobs', 'dev3-ai-job-board' ) . '</label><span class="dev3jb-inputicon">' . self::icon( 'search' ) . '<input id="' . esc_attr( $uid ) . '" type="search" name="q" placeholder="' . esc_attr__( 'Job title, skill or company', 'dev3-ai-job-board' ) . '"></span><button class="dev3jb-btn dev3jb-btn--primary" type="submit">' . esc_html__( 'Search', 'dev3-ai-job-board' ) . '</button></form>';
	}
}
