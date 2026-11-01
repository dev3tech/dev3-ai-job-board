<?php
/**
 * Builds a small, valid one-page PDF with the given text lines (Flate-compressed content stream).
 *
 * @package Dev3\AiJobBoard
 */

/**
 * PDF bytes.
 *
 * @param string[] $lines    Lines.
 * @param bool     $compress Flate the content stream.
 * @return string
 */
function dev3jb_test_pdf( array $lines, $compress = true ) {
	$ops = "BT\n/F1 11 Tf\n72 760 Td\n14 TL\n";
	foreach ( $lines as $i => $line ) {
		$esc  = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $line );
		$ops .= ( 0 === $i ? '' : "T*\n" ) . '(' . $esc . ") Tj\n";
	}
	$ops    .= "ET\n";
	$stream  = $compress ? gzcompress( $ops ) : $ops;
	$filter  = $compress ? ' /Filter /FlateDecode' : '';
	$objects = array(
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
		'<< /Length ' . strlen( $stream ) . $filter . " >>\nstream\n" . $stream . "\nendstream",
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
	);
	$pdf     = "%PDF-1.4\n";
	$offsets = array();
	foreach ( $objects as $n => $body ) {
		$offsets[] = strlen( $pdf );
		$pdf      .= ( $n + 1 ) . " 0 obj\n" . $body . "\nendobj\n";
	}
	$xref = strlen( $pdf );
	$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offsets as $o ) {
		$pdf .= sprintf( "%010d 00000 n \n", $o );
	}
	$pdf .= "trailer\n<< /Size " . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
	return $pdf;
}
