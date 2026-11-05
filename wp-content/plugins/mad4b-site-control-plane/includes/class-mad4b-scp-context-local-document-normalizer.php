<?php
if(!defined('ABSPATH'))exit;
/** Provider-neutral, read-only local document normalizers. No Drive OAuth, API or executable macros. */
final class MAD4B_SCP_Context_Local_Document_Normalizer {
 const MAX_TEXT_BYTES = 262144;
 const MAX_BINARY_BYTES = 16777216;
	private static function normalization_record( $content, $complete = true, $status = 'ready', $reason = '', $bytes = null ) {
		$content = wp_check_invalid_utf8( (string) $content, true );
		$observed = null === $bytes ? strlen( $content ) : (int) $bytes;
		if ( strlen( $content ) > self::MAX_TEXT_BYTES ) {
			return array(
				'content' => substr( $content, 0, self::MAX_TEXT_BYTES ),
				'complete' => false,
				'bytes' => $observed,
				'normalization_status' => 'incomplete',
				'normalization_reason' => 'max_text_bytes_exceeded',
			);
		}
		return array(
			'content' => $content,
			'complete' => (bool) $complete,
			'bytes' => $observed,
			'normalization_status' => sanitize_key( (string) $status ),
			'normalization_reason' => sanitize_key( (string) $reason ),
		);
	}


	private static function zip_entries( $binary ) {
		if ( ! class_exists( 'ZipArchive' ) ) return new WP_Error( 'mad4b_context_ziparchive_unavailable', 'ZipArchive is required to normalize this document type.' );
		$tmp = tempnam( sys_get_temp_dir(), 'mad4b-context-' );
		if ( false === $tmp ) return new WP_Error( 'mad4b_context_tempfile_unavailable', 'Unable to allocate a bounded normalization tempfile.' );
		$written = @file_put_contents( $tmp, (string) $binary );
		if ( false === $written || $written !== strlen( (string) $binary ) ) { @unlink( $tmp ); return new WP_Error( 'mad4b_context_tempfile_write_failed', 'Unable to write document normalization tempfile.' ); }
		$zip = new ZipArchive();
		$opened = $zip->open( $tmp );
		if ( true !== $opened ) { @unlink( $tmp ); return new WP_Error( 'mad4b_context_archive_invalid', 'Document archive could not be opened.' ); }
		$entries = array();
		$total = 0;
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) || empty( $stat['name'] ) ) continue;
			$name = (string) $stat['name'];
			if ( substr( $name, -1 ) === '/' ) continue;
			$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$total += max( 0, $size );
			if ( $total > self::MAX_BINARY_BYTES * 4 ) { $zip->close(); @unlink( $tmp ); return new WP_Error( 'mad4b_context_archive_expanded_too_large', 'Expanded document archive exceeds the certified normalization limit.' ); }
			$data = $zip->getFromIndex( $i );
			if ( false !== $data ) $entries[ $name ] = (string) $data;
		}
		$zip->close();
		@unlink( $tmp );
		return $entries;
	}


	private static function xml_visible_text( $xml ) {
		$xml = (string) $xml;
		$xml = preg_replace( '/<\/(?:[A-Za-z0-9_\-]+:)?(?:p|row|tr|text:p|text:h)>/i', "\n", $xml );
		$xml = preg_replace( '/<(?:[A-Za-z0-9_\-]+:)?(?:br|tab)[^>]*\/?\s*>/i', "\t", $xml );
		$text = html_entity_decode( strip_tags( $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/u", ' ', $text );
		$text = preg_replace( "/\r\n?|\n{3,}/u", "\n", $text );
		return trim( (string) $text );
	}


	private static function normalize_docx( $binary ) {
		$entries = self::zip_entries( $binary );
		if ( is_wp_error( $entries ) ) return $entries;
		$parts = array();
		foreach ( $entries as $name => $xml ) {
			if ( preg_match( '#^word/(document|header[0-9]*|footer[0-9]*|footnotes|endnotes|comments)\.xml$#i', $name ) ) {
				$text = self::xml_visible_text( $xml );
				if ( '' !== $text ) $parts[] = $text;
			}
		}
		return self::normalization_record( implode( "\n\n", $parts ), true, 'ready', 'docx_local' );
	}

    /** Read-only workbook analysis. Coordinates, sheet titles and cached formula values remain visible. */

    private static function workbook_xml( $xml ) {
        if ( ! is_string( $xml ) || false !== stripos( $xml, '<!DOCTYPE' ) || ! class_exists( 'DOMDocument' ) )
            return new WP_Error( 'mad4b_context_spreadsheet_xml_invalid', 'Spreadsheet XML is invalid or unsupported.' );
        $dom = new DOMDocument();
        if ( ! @$dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) )
            return new WP_Error( 'mad4b_context_spreadsheet_xml_invalid', 'Spreadsheet XML could not be parsed.' );
        return $dom;
    }

    private static function normalize_xlsx( $binary ) {
        $entries = self::zip_entries( $binary );
        if ( is_wp_error( $entries ) ) return $entries;
        if ( empty( $entries['xl/workbook.xml'] ) || empty( $entries['xl/_rels/workbook.xml.rels'] ) )
            return new WP_Error( 'mad4b_context_spreadsheet_structure_missing', 'Excel workbook and relationships are required.' );
        $workbook = self::workbook_xml( $entries['xl/workbook.xml'] );
        $rels = self::workbook_xml( $entries['xl/_rels/workbook.xml.rels'] );
        if ( is_wp_error( $workbook ) ) return $workbook;
        if ( is_wp_error( $rels ) ) return $rels;
        $rx = new DOMXPath( $rels );
        $targets = array();
        foreach ( $rx->query( '//*[local-name()="Relationship"]' ) as $rel ) {
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            if ( '' === $id || '' === $target || false !== strpos( $target, '..' ) ) continue;
            $target = ltrim( $target, '/' );
            $targets[ $id ] = 0 === strpos( $target, 'xl/' ) ? $target : 'xl/' . $target;
        }
        $shared = array();
        if ( isset( $entries['xl/sharedStrings.xml'] ) ) {
            $strings = self::workbook_xml( $entries['xl/sharedStrings.xml'] );
            if ( is_wp_error( $strings ) ) return $strings;
            $sx = new DOMXPath( $strings );
            foreach ( $sx->query( '//*[local-name()="si"]' ) as $si ) {
                $text = '';
                foreach ( $sx->query( './/*[local-name()="t"]', $si ) as $node ) $text .= $node->textContent;
                $shared[] = $text;
                if ( count( $shared ) > 20000 )
                    return new WP_Error( 'mad4b_context_spreadsheet_capacity', 'Excel shared strings exceed the inventory limit.' );
            }
        }
        $wx = new DOMXPath( $workbook );
        $out = array();
        $sheet_count = 0;
        $cell_count = 0;
        foreach ( $wx->query( '//*[local-name()="sheets"]/*[local-name()="sheet"]' ) as $sheet ) {
            ++$sheet_count;
            if ( $sheet_count > 24 )
                return new WP_Error( 'mad4b_context_spreadsheet_capacity', 'Excel workbook exceeds 24 sheets.' );
            $name = $sheet->getAttribute( 'name' );
            $rel_id = $sheet->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
            $path = $targets[ $rel_id ] ?? '';
            if ( ! preg_match( '#^xl/worksheets/[a-zA-Z0-9_-]+\.xml$#', $path ) || ! isset( $entries[ $path ] ) )
                return new WP_Error( 'mad4b_context_spreadsheet_sheet_missing', 'Excel worksheet relationship is missing.' );
            $dom = self::workbook_xml( $entries[ $path ] );
            if ( is_wp_error( $dom ) ) return $dom;
            $xp = new DOMXPath( $dom );
            $lines = array();
            $last_row = 0;
            foreach ( $xp->query( '//*[local-name()="sheetData"]/*[local-name()="row"]' ) as $row ) {
                $row_number = (int) $row->getAttribute( 'r' );
                if ( $row_number < 1 || $row_number > 2000 || $row_number <= $last_row )
                    return new WP_Error( 'mad4b_context_spreadsheet_row_invalid', 'Spreadsheet row number is missing, duplicated or over the limit.' );
                $last_row = $row_number;
                $values = array();
                foreach ( $xp->query( './*[local-name()="c"]', $row ) as $cell ) {
                    ++$cell_count;
                    if ( $cell_count > 20000 )
                        return new WP_Error( 'mad4b_context_spreadsheet_capacity', 'Excel workbook exceeds 20,000 cells.' );
                    $coord = strtoupper( $cell->getAttribute( 'r' ) );
                    if ( ! preg_match( '/^([A-Z]{1,3})([0-9]{1,7})$/', $coord, $parts ) || (int)$parts[2] !== $row_number )
                        return new WP_Error( 'mad4b_context_spreadsheet_cell_invalid', 'Spreadsheet cell reference is invalid.' );
                    $column = 0;
                    foreach ( str_split( $parts[1] ) as $letter ) $column = $column * 26 + ord( $letter ) - 64;
                    if ( $column < 1 || $column > 64 )
                        return new WP_Error( 'mad4b_context_spreadsheet_capacity', 'Excel workbook exceeds 64 columns.' );
                    $type = $cell->getAttribute( 't' );
                    $val_node = $xp->query( './*[local-name()="v"]', $cell )->item(0);
                    $formula = $xp->query( './*[local-name()="f"]', $cell )->item(0);
                    if ( $formula && ! $val_node )
                        return new WP_Error( 'mad4b_context_spreadsheet_formula_uncached', 'Formula has no cached value; no formula execution is permitted.' );
                    if ( 'e' === $type )
                        return new WP_Error( 'mad4b_context_spreadsheet_formula_error', 'Excel cached formula contains an error.' );
                    if ( 'inlineStr' === $type ) {
                        $value = '';
                        foreach ( $xp->query( './*[local-name()="is"]//*[local-name()="t"]', $cell ) as $node ) $value .= $node->textContent;
                    } else {
                        $value = $val_node ? $val_node->textContent : '';
                        if ( 's' === $type ) {
                            if ( ! ctype_digit( (string) $value ) || ! array_key_exists( (int)$value, $shared ) )
                                return new WP_Error( 'mad4b_context_spreadsheet_shared_string_invalid', 'Excel shared string index is invalid.' );
                            $value = $shared[ (int)$value ];
                        } elseif ( 'b' === $type ) $value = '1' === $value ? 'TRUE' : 'FALSE';
                    }
                    $value = trim( preg_replace( '/[\r\n\t]+/u', ' ', (string) $value ) );
                    $values[ $column ] = $value;
                }
                if ( $values ) {
                    $maxcol = max( array_keys($values) );
                    $ordered = array();
                    for ( $c=1; $c<=$maxcol; ++$c ) $ordered[] = $values[$c] ?? '';
                    $lines[] = $row_number . "\t" . implode( "\t", $ordered );
                }
            }
            $out[] = '[Sheet: ' . str_replace( array("\r", "\n", "\t"), ' ', $name ) . '; rows=' . count($lines) . ']' . "\n"
                . 'Row' . "\t" . 'A..' . "\n" . implode("\n", $lines);
        }
        if ( ! $sheet_count || ! $cell_count )
            return new WP_Error( 'mad4b_context_spreadsheet_empty', 'Excel workbook has no readable cell data.' );
        return self::normalization_record( implode( "\n\n", $out ), true, 'ready', 'xlsx_structured_local' );
    }
    /** Local workbook parser shared with Media; no remote provider request or macro execution. */

    public static function normalize_local_spreadsheet_bytes( $mime, $binary ) {
        if ( ! in_array( (string)$mime, array(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12'
        ), true ) ) return new WP_Error( 'mad4b_context_spreadsheet_mime_invalid', 'Only OOXML Excel workbooks are supported.' );
        if ( ! is_string($binary) || strlen($binary) > 10485760 )
            return new WP_Error( 'mad4b_context_spreadsheet_size_invalid', 'Excel workbook exceeds 10 MiB.' );
        return self::normalize_xlsx( $binary );
    }


	private static function pdf_decode_literal( $value ) {
		$value = preg_replace_callback( '/\\\\([0-7]{1,3})/', static function ( $m ) { return chr( octdec( $m[1] ) ); }, (string) $value );
		return strtr( $value, array( '\\n' => "\n", '\\r' => "\r", '\\t' => "\t", '\\b' => "\b", '\\f' => "\f", '\\(' => '(', '\\)' => ')', '\\\\' => '\\' ) );
	}


	private static function pdf_stream_text( $stream ) {
		$out = array();
		if ( preg_match_all( '/BT(.*?)ET/s', (string) $stream, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				if ( preg_match_all( '/\((?:\\\\.|[^\\)])*\)/s', $block, $strings ) ) {
					foreach ( $strings[0] as $literal ) {
						$value = self::pdf_decode_literal( substr( $literal, 1, -1 ) );
						if ( '' !== trim( $value ) ) $out[] = $value;
					}
				}
				if ( preg_match_all( '/<([0-9A-Fa-f]{4,})>\s*(?:Tj|TJ|\'|\")/', $block, $hexes ) ) {
					foreach ( $hexes[1] as $hex ) {
						if ( strlen( $hex ) % 2 ) $hex .= '0';
						$bytes = @hex2bin( $hex );
						if ( false === $bytes ) continue;
						if ( 0 === strncmp( $bytes, "\xFE\xFF", 2 ) && function_exists( 'mb_convert_encoding' ) ) $bytes = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
						if ( '' !== trim( $bytes ) ) $out[] = $bytes;
					}
				}
			}
		}
		return trim( implode( ' ', $out ) );
	}

	/** Pure local PDF normalization facade shared with WordPress Media; no Google API calls. */

	public static function normalize_local_pdf_bytes( $binary ) {
		if ( ! is_string( $binary ) || strlen( $binary ) > 10485760 )
			return new WP_Error( 'mad4b_context_local_pdf_limit', 'Local PDF exceeds the certified 10 MiB limit.' );
		return self::normalize_pdf( $binary );
	}


	private static function normalize_pdf( $binary ) {
		$binary = (string) $binary;
		if ( 0 !== strpos( $binary, '%PDF-' ) ) return new WP_Error( 'mad4b_context_pdf_invalid', 'PDF signature is invalid.' );
		$texts = array();
		$unsupported_filter = false;
		if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $binary, $streams, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $streams[1] as $capture ) {
				$data = (string) $capture[0];
				$offset = (int) $capture[1];
				$prefix = substr( $binary, max( 0, $offset - 512 ), min( 512, $offset ) );
				if ( preg_match( '#/(DCTDecode|JPXDecode|LZWDecode|ASCII85Decode|CCITTFaxDecode)#', $prefix ) ) $unsupported_filter = true;
				if ( false !== strpos( $prefix, '/FlateDecode' ) ) {
					$decoded = @gzuncompress( $data );
					if ( false === $decoded ) $decoded = @gzinflate( $data );
					if ( false === $decoded ) { $unsupported_filter = true; continue; }
					$data = $decoded;
				}
				$text = self::pdf_stream_text( $data );
				if ( '' !== $text ) $texts[] = $text;
			}
		}
		$text = trim( implode( "\n", $texts ) );
		if ( '' === $text ) return self::normalization_record( '', false, 'extractor_required', 'pdf_ocr_required', strlen( $binary ) );
		return self::normalization_record( $text, ! $unsupported_filter, $unsupported_filter ? 'incomplete' : 'ready', $unsupported_filter ? 'pdf_mixed_filters_not_fully_decoded' : 'pdf_local_text', strlen( $binary ) );
	}


 public static function normalize_local_docx_bytes( $mime, $binary ) {
  if((string)$mime!=='application/vnd.openxmlformats-officedocument.wordprocessingml.document')
    return new WP_Error('mad4b_context_docx_mime_invalid','Only non-macro DOCX is supported.');
  if(!is_string($binary)||strlen($binary)>10485760)
    return new WP_Error('mad4b_context_docx_size_invalid','DOCX exceeds 10 MiB.');
  return self::normalize_docx($binary);
 }
}
