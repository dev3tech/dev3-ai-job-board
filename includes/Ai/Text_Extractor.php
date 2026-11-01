<?php
/**
 * Plain-text extraction from resumes without AI: PDF (text operators in plain or Flate streams),
 * Word .docx (word/document.xml) and plain text. Scanned PDFs have no text layer; DEV3 AI reads
 * those through vision instead.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Static extractor.
 */
class Text_Extractor {

	const MAX_CHARS = 20000;

	/**
	 * Text of a file.
	 *
	 * @param string $path File path.
	 * @param string $mime MIME type.
	 * @return string
	 */
	public static function extract( $path, $mime ) {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$data = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		switch ( $mime ) {
			case 'application/pdf':
				$text = self::pdf( $data );
				break;
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				$text = self::docx( $path );
				break;
			default:
				$text = self::clean( $data );
		}
		return mb_substr( $text, 0, self::MAX_CHARS );
	}

	/**
	 * Normalises whitespace, drops control chars and invalid UTF-8.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = preg_replace( "/[ \t]+/u", ' ', (string) $text );
		$text = preg_replace( "/ *\n */u", "\n", (string) $text );
		$text = preg_replace( "/\n{3,}/u", "\n\n", (string) $text );
		return trim( (string) $text );
	}

	/**
	 * Inflates a Flate stream without emitting warnings on bad data.
	 *
	 * @param string $data Compressed.
	 * @return string
	 */
	private static function inflate( $data ) {
		set_error_handler( static fn() => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$out = function_exists( 'gzuncompress' ) ? gzuncompress( $data ) : false;
		if ( false === $out && function_exists( 'gzinflate' ) ) {
			$out = gzinflate( $data );
			if ( false === $out ) {
				$out = gzinflate( substr( $data, 2 ) );
			}
		}
		restore_error_handler();
		return false === $out ? '' : (string) $out;
	}

	/**
	 * Text from a PDF.
	 *
	 * @param string $data Raw PDF.
	 * @return string
	 */
	public static function pdf( $data ) {
		if ( 0 !== strpos( $data, '%PDF' ) ) {
			return '';
		}
		$chunks = array();
		if ( preg_match_all( '/<<(.*?)>>\s*stream\r?\n(.*?)\r?\n?endstream/s', $data, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $stream ) {
				$dict = $stream[1];
				if ( preg_match( '#/Subtype\s*/Image|/Type\s*/XObject|/FontFile|/Type\s*/Metadata#', $dict ) ) {
					continue;
				}
				$body = $stream[2];
				if ( false !== strpos( $dict, '/FlateDecode' ) ) {
					$body = self::inflate( $body );
				} elseif ( preg_match( '#/Filter#', $dict ) ) {
					continue; // Other filters (DCT, LZW…) are images or rare: skip.
				}
				if ( '' !== $body && ( false !== strpos( $body, 'BT' ) ) ) {
					$chunks[] = self::content_text( $body );
				}
			}
		}
		return self::clean( implode( "\n", array_filter( $chunks ) ) );
	}

	/**
	 * Text operators of one content stream.
	 *
	 * @param string $content Content stream.
	 * @return string
	 */
	public static function content_text( $content ) {
		$out = '';
		if ( ! preg_match_all( '/BT(.*?)ET/s', $content, $blocks ) ) {
			return '';
		}
		foreach ( $blocks[1] as $block ) {
			$line = '';
			// Tokens: strings, arrays, and the operators that move to a new line.
			preg_match_all( '/\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]+>|\[(?:[^\]\\\\]|\\\\.)*\]|-?\d*\.?\d+\s+-?\d*\.?\d+\s+T[dD]|T\*|\'|"|Tj|TJ/s', $block, $tokens );
			foreach ( $tokens[0] as $tok ) {
				$first = $tok[0];
				if ( '(' === $first ) {
					$line .= self::literal( substr( $tok, 1, -1 ) );
				} elseif ( '<' === $first ) {
					$line .= self::hex( substr( $tok, 1, -1 ) );
				} elseif ( '[' === $first ) {
					preg_match_all( '/\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f\s]+>|-?\d+\.?\d*/s', substr( $tok, 1, -1 ), $parts );
					foreach ( $parts[0] as $part ) {
						if ( '(' === $part[0] ) {
							$line .= self::literal( substr( $part, 1, -1 ) );
						} elseif ( '<' === $part[0] ) {
							$line .= self::hex( substr( $part, 1, -1 ) );
						} elseif ( (float) $part < -200 ) {
							$line .= ' '; // A large negative kerning gap is a word space.
						}
					}
				} elseif ( 'T*' === $tok || "'" === $tok || '"' === $tok ) {
					$line .= "\n";
				} elseif ( preg_match( '/(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+T[dD]/', $tok, $td ) ) {
					if ( abs( (float) $td[2] ) > 0.01 ) {
						$line .= "\n";
					} elseif ( (float) $td[1] > 0.01 && '' !== $line && ' ' !== substr( $line, -1 ) ) {
						$line .= ' ';
					}
				}
			}
			$out .= $line . "\n";
		}
		return $out;
	}

	/**
	 * Decodes a PDF literal string.
	 *
	 * @param string $s Inner string.
	 * @return string
	 */
	public static function literal( $s ) {
		$s = preg_replace_callback(
			'/\\\\([0-7]{1,3}|.)/s',
			static function ( $m ) {
				$map = array(
					'n'  => "\n",
					'r'  => "\r",
					't'  => "\t",
					'b'  => '',
					'f'  => '',
					'('  => '(',
					')'  => ')',
					'\\' => '\\',
				);
				if ( isset( $map[ $m[1] ] ) ) {
					return $map[ $m[1] ];
				}
				if ( preg_match( '/^[0-7]+$/', $m[1] ) ) {
					return chr( octdec( $m[1] ) % 256 );
				}
				return "\n" === $m[1] || "\r" === $m[1] ? '' : $m[1];
			},
			$s
		);
		return self::to_utf8( (string) $s );
	}

	/**
	 * Decodes a PDF hex string (UTF-16BE when it has a BOM or many zero bytes).
	 *
	 * @param string $hex Hex digits.
	 * @return string
	 */
	public static function hex( $hex ) {
		$hex = preg_replace( '/\s+/', '', $hex );
		if ( strlen( $hex ) % 2 ) {
			$hex .= '0';
		}
		$bin = (string) hex2bin( $hex );
		return self::to_utf8( $bin );
	}

	/**
	 * Converts PDF string bytes to UTF-8.
	 *
	 * @param string $bin Bytes.
	 * @return string
	 */
	private static function to_utf8( $bin ) {
		if ( "\xFE\xFF" === substr( $bin, 0, 2 ) ) {
			return (string) mb_convert_encoding( substr( $bin, 2 ), 'UTF-8', 'UTF-16BE' );
		}
		$len = strlen( $bin );
		if ( $len >= 2 && substr_count( $bin, "\0" ) >= $len / 3 ) {
			return (string) mb_convert_encoding( $bin, 'UTF-8', 'UTF-16BE' );
		}
		return mb_check_encoding( $bin, 'UTF-8' ) ? $bin : (string) mb_convert_encoding( $bin, 'UTF-8', 'Windows-1252' );
	}

	/**
	 * Text of a .docx file.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function docx( $path ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return '';
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}
		$xml = (string) $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( '' === $xml ) {
			return '';
		}
		$xml = str_replace( array( '</w:p>', '<w:br/>', '<w:tab/>' ), array( "\n", "\n", ' ' ), $xml );
		return self::clean( html_entity_decode( wp_strip_all_tags( $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
	}
}
