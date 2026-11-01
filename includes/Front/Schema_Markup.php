<?php
/**
 * Schema.org JobPosting JSON-LD for Google for Jobs.
 *
 * Required by Google: title, description, datePosted, hiringOrganization, and jobLocation (or
 * applicantLocationRequirements + jobLocationType TELECOMMUTE for remote jobs). We also add
 * validThrough, employmentType, baseSalary, identifier and directApply when known.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Front;

use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static output.
 */
class Schema_Markup {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 20 );
	}

	/**
	 * Prints JSON-LD on live single job pages.
	 */
	public static function output() {
		if ( ! Settings::get( 'schema_enabled', true ) || ! is_singular( Post_Types::JOB ) ) {
			return;
		}
		$job = Listings::get( get_queried_object_id() );
		if ( ! $job || 'publish' !== $job['status'] || $job['filled'] ) {
			return;
		}
		echo '<script type="application/ld+json">' . wp_json_encode( self::build( $job ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
	}

	/**
	 * JobPosting array.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function build( array $job ) {
		$company = $job['company'];
		$data    = array(
			'@context'           => 'https://schema.org/',
			'@type'              => 'JobPosting',
			'title'              => $job['title'],
			'description'        => wp_kses( wpautop( $job['content'] ), array( 'p' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'br' => array(), 'strong' => array(), 'em' => array(), 'h3' => array(), 'h4' => array() ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'datePosted'         => mysql2date( 'c', get_date_from_gmt( $job['date'] ), false ),
			'identifier'         => array(
				'@type' => 'PropertyValue',
				'name'  => $company ? $company['name'] : get_bloginfo( 'name' ),
				'value' => (string) $job['id'],
			),
			'hiringOrganization' => array_filter(
				array(
					'@type'  => 'Organization',
					'name'   => $company ? $company['name'] : get_bloginfo( 'name' ),
					'sameAs' => $company && $company['website'] ? $company['website'] : home_url( '/' ),
					'logo'   => $company && $company['logo'] ? $company['logo'] : '',
				)
			),
			'directApply'        => 'internal' === $job['apply_method'],
		);
		if ( $job['expires'] ) {
			$data['validThrough'] = gmdate( 'c', strtotime( $job['expires'] . ' 23:59:59 UTC' ) );
		}
		$types = array();
		foreach ( $job['types'] as $t ) {
			$schema  = (string) get_term_meta( $t['id'], 'dev3jb_schema', true );
			$types[] = '' !== $schema ? $schema : 'OTHER';
		}
		if ( $types ) {
			$data['employmentType'] = 1 === count( $types ) ? $types[0] : array_values( array_unique( $types ) );
		}
		$places = wp_list_pluck( $job['locations'], 'name' );
		if ( 'remote' === $job['workplace'] ) {
			$data['jobLocationType']               = 'TELECOMMUTE';
			$data['applicantLocationRequirements'] = array(
				'@type' => 'Country',
				'name'  => '' !== $job['remote_region'] ? $job['remote_region'] : ( $places ? end( $places ) : 'Worldwide' ),
			);
		}
		if ( 'remote' !== $job['workplace'] || $places ) {
			$locality = $places ? $places[0] : '';
			$region   = '';
			$country  = '';
			foreach ( $job['locations'] as $loc ) {
				$term = get_term( $loc['id'] );
				if ( $term && ! is_wp_error( $term ) && $term->parent ) {
					$parent   = get_term( $term->parent );
					$country  = $parent && ! is_wp_error( $parent ) ? html_entity_decode( $parent->name, ENT_QUOTES, 'UTF-8' ) : '';
					$locality = $loc['name'];
				}
			}
			$data['jobLocation'] = array(
				'@type'   => 'Place',
				'address' => array_filter(
					array(
						'@type'           => 'PostalAddress',
						'streetAddress'   => $job['address'],
						'addressLocality' => $locality,
						'addressRegion'   => $region,
						'addressCountry'  => $country,
					)
				),
			);
		}
		if ( ! $job['salary_hidden'] && ( null !== $job['salary_min'] || null !== $job['salary_max'] ) ) {
			$units = array(
				'hour'  => 'HOUR',
				'day'   => 'DAY',
				'week'  => 'WEEK',
				'month' => 'MONTH',
				'year'  => 'YEAR',
			);
			$value = array(
				'@type'    => 'QuantitativeValue',
				'unitText' => isset( $units[ $job['salary_period'] ] ) ? $units[ $job['salary_period'] ] : 'YEAR',
			);
			if ( null !== $job['salary_min'] && null !== $job['salary_max'] && $job['salary_min'] !== $job['salary_max'] ) {
				$value['minValue'] = $job['salary_min'];
				$value['maxValue'] = $job['salary_max'];
			} else {
				$value['value'] = null !== $job['salary_min'] ? $job['salary_min'] : $job['salary_max'];
			}
			$data['baseSalary'] = array(
				'@type'    => 'MonetaryAmount',
				'currency' => $job['salary_currency'],
				'value'    => $value,
			);
		}
		$levels = array(
			'entry'     => 'entry level',
			'mid'       => 'mid level',
			'senior'    => 'senior',
			'lead'      => 'lead',
			'executive' => 'executive',
		);
		if ( isset( $levels[ $job['experience'] ] ) ) {
			$data['experienceRequirements'] = $levels[ $job['experience'] ];
		}
		if ( $job['skills'] ) {
			$data['skills'] = implode( ', ', wp_list_pluck( $job['skills'], 'name' ) );
		}
		if ( $job['categories'] ) {
			$data['industry'] = $job['categories'][0]['name'];
		}
		/**
		 * Filters the JobPosting JSON-LD.
		 *
		 * @param array $data JSON-LD.
		 * @param array $job  Job.
		 */
		return apply_filters( 'dev3jb_schema_job', $data, $job );
	}
}
