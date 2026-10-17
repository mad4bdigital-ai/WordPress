<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IMP07 strictly bounded XLSX → review-only rows.
 * Relies on a host-installed, vetted PhpSpreadsheet reader; never downloads
 * an executable parser or evaluates an Excel formula.
 */
final class MAD4B_SCP_Activity_Import_Xlsx {
    const MAX_FILE_BYTES = 1048576;
    const MAX_UNCOMPRESSED = 8388608;
    const MAX_CELLS = 40080;
    public static function available() {
        return defined( 'MAD4B_IMPORT_XLSX_PARSER_APPROVED' ) &&
            true === MAD4B_IMPORT_XLSX_PARSER_APPROVED &&
            class_exists( 'ZipArchive' ) &&
            class_exists( '\PhpOffice\\PhpSpreadsheet\\IOFactory' ) &&
            class_exists( '\PhpOffice\\PhpSpreadsheet\\Cell\\Coordinate' ) &&
            class_exists( '\PhpOffice\\PhpSpreadsheet\\Cell\\DataType' );
    }
    private static function err( $code, $message ) {
        return new WP_Error( $code, $message );
    }
    public static function parse( $file ) {
        if ( ! self::available() )
            return self::err( 'mad4b_xlsx_parser_unavailable',
                'The site has no certified PhpSpreadsheet/XLSX conversion runtime.' );
        if ( ! is_array( $file ) || ! isset( $file['name'], $file['tmp_name'],
            $file['size'], $file['error'] ) ||
            UPLOAD_ERR_OK !== (int) $file['error'] ||
            ! is_string( $file['tmp_name'] ) ||
            ! is_uploaded_file( $file['tmp_name'] ) ||
            ! is_string( $file['name'] ) ||
            ! preg_match( '/\\.xlsx$/iD', $file['name'] ) ||
            (int) $file['size'] < 1 ||
            (int) $file['size'] > self::MAX_FILE_BYTES )
            return self::err( 'mad4b_xlsx_upload_invalid',
                'Only a genuine .xlsx file up to 1 MiB is accepted.' );
        $zip = new ZipArchive();
        $readonly_flag = defined( 'ZipArchive::RDONLY' ) ?
            ZipArchive::RDONLY : 0;
        if ( true !== $zip->open( $file['tmp_name'], $readonly_flag ) )
            return self::err( 'mad4b_xlsx_zip_invalid', 'Excel ZIP container invalid.' );
        $total = 0;
        $has_workbook = false; $has_sheet = false;
        $zip_issue = false;
        if ( $zip->numFiles > 150 ) $zip_issue = true;
        for ( $i = 0; !$zip_issue && $i < $zip->numFiles; $i++ ) {
            $info = $zip->statIndex( $i );
            if ( ! is_array( $info ) || ! isset( $info['name'], $info['size'],
                $info['comp_size'] ) ) { $zip_issue = true; break; }
            $name = (string) $info['name'];
            $length = (int) $info['size'];
            $compressed = (int) $info['comp_size'];
            $total += $length;
            if ( $length < 0 || $compressed < 0 ||
                $length > self::MAX_UNCOMPRESSED ||
                $total > self::MAX_UNCOMPRESSED ||
                ( $length > 1024 && $compressed < 1 ) ||
                ( $compressed > 0 && $length > $compressed * 200 ) ||
                strpos( $name, '..' ) !== false ||
                strpos( $name, '\\' ) !== false ||
                0 === strpos( $name, '/' ) ||
                preg_match( '/(?:vbaProject|externalLinks|embeddings|activeX|connections|queryTables)/i',
                    $name ) ) {
                $zip_issue = true; break;
            }
            if ( preg_match( '/\\.rels$/iD', $name ) ) {
                $relationships = $zip->getFromIndex( $i );
                if ( ! is_string( $relationships ) ||
                    preg_match( '/TargetMode\\s*=\\s*["\\x27]External["\\x27]/i',
                        $relationships ) ) {
                    $zip_issue = true; break;
                }
            }
            if ( 'xl/workbook.xml' === $name ) $has_workbook = true;
            if ( 'xl/worksheets/sheet1.xml' === $name ) $has_sheet = true;
        }
        $zip->close();
        if ( $zip_issue || !$has_workbook || !$has_sheet )
            return self::err( 'mad4b_xlsx_zip_unsafe',
                'Complex, oversized or externally linked spreadsheets require a separately certified converter.' );
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader( 'Xlsx' );
            $reader->setReadDataOnly( true );
            $names = $reader->listWorksheetNames( $file['tmp_name'] );
            if ( ! is_array( $names ) || count( $names ) !== 1 )
                return self::err( 'mad4b_xlsx_single_sheet_required',
                    'One worksheet per reviewed source is supported. Export each sheet separately.' );
            $reader->setLoadSheetsOnly( array( $names[0] ) );
            $workbook = $reader->load( $file['tmp_name'] );
            $sheet = $workbook->getActiveSheet();
            $row_count = (int) $sheet->getHighestDataRow();
            $cols = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $sheet->getHighestDataColumn() );
            if ( $row_count < 2 || $row_count > 501 || $cols < 1 ||
                $cols > 80 || $row_count * $cols > self::MAX_CELLS ) {
                $workbook->disconnectWorksheets();
                return self::err( 'mad4b_xlsx_dimensions_invalid',
                    'Excel preview must contain 1–500 data rows and 1–80 columns.' );
            }
            $headers = array();
            for ( $c = 1; $c <= $cols; $c++ ) {
                $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . '1';
                $value = $sheet->getCell( $addr );
                if ( $value->getDataType() ===
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA ) {
                    $workbook->disconnectWorksheets();
                    return self::err( 'mad4b_xlsx_formula_denied',
                        'A formula was found in the Excel header.' );
                }
                $headers[] = trim( (string) $value->getValue() );
            }
            $rows = array();
            for ( $r = 2; $r <= $row_count; $r++ ) {
                $row = array(); $empty = true;
                for ( $c = 1; $c <= $cols; $c++ ) {
                    $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $r;
                    $cell = $sheet->getCell( $addr );
                    if ( $cell->getDataType() ===
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_FORMULA ) {
                        $workbook->disconnectWorksheets();
                        return self::err( 'mad4b_xlsx_formula_denied',
                            'Workbook formulas must be converted to literal values by their author.' );
                    }
                    $value = $cell->getValue();
                    if ( ! is_scalar( $value ) && null !== $value ) {
                        $workbook->disconnectWorksheets();
                        return self::err( 'mad4b_xlsx_cell_type_invalid',
                            'Excel source contains unsupported nested or rich-cell values.' );
                    }
                    $text = null === $value ? '' : (string) $value;
                    if ( strlen( $text ) > 4096 ) {
                        $workbook->disconnectWorksheets();
                        return self::err( 'mad4b_xlsx_cell_oversized', 'Excel cell exceeds review policy.' );
                    }
                    if ( '' !== $text ) $empty = false;
                    $row[ $headers[ $c - 1 ] ] = $text;
                }
                if ( !$empty ) $rows[] = $row;
            }
            $workbook->disconnectWorksheets();
            if ( ! $rows )
                return self::err( 'mad4b_xlsx_no_data', 'Workbook contains no reviewable data rows.' );
            return array( 'headers' => $headers, 'rows' => $rows,
                'source_format' => 'xlsx', 'formulas_evaluated' => false,
                'worksheet_count' => 1, 'post_writes' => 0 );
        } catch ( \Throwable $error ) {
            return self::err( 'mad4b_xlsx_parse_failed',
                'Unable to parse the bounded XLSX source safely. Use literal CSV as the fallback.' );
        }
    }
}
