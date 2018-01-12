<?php

namespace AppBundle\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Tcpdf;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportService {

  private $pointer;

  public function getFile( $data, $format = 'xls' ) {

    $this->pointer = array( 1, 1 );
    $spreadsheet   = new Spreadsheet();
    $sheet         = $spreadsheet->getActiveSheet();
    $sheet->setTitle( 'Log report' );
    $this->writeInfoHeaders( $sheet, $data );
    $this->writeUser( $sheet, $data );
    $this->writeUserLogs( $sheet, $data );

    switch ( $format ) {
      case 'xls':
        $writerClass = Xls::class;
        break;
      case 'csv':
        $writerClass = Csv::class;
        break;
      case 'pdf':
        $writerClass = Tcpdf::class;
        break;
      case 'xlsx':
        $writerClass = Xlsx::class;
        break;
      default:
        throw new \Exception( "Tipo no soportado" );
    }

    ob_start();
    $writer = new $writerClass( $spreadsheet );
    $writer->save( 'php://output' );

    return ob_get_clean();
  }

  private function columnLetter( $c ) {
    $c = intval( $c );
    if ( $c <= 0 ) {
      return '';
    }
    $letter = '';
    while ( $c != 0 ) {
      $p      = ( $c - 1 ) % 26;
      $c      = intval( ( $c - $p ) / 26 );
      $letter = chr( 65 + $p ) . $letter;
    }

    return $letter;
  }

  private function getPointer() {
    return $this->columnLetter( $this->pointer[0] ) . strval( $this->pointer[1] );
  }

  private function movePointer( $horizontal = 0, $vertical = 0 ) {
    $this->pointer[0] += $horizontal;
    $this->pointer[1] += $vertical;

    return $this->getPointer();
  }

  private function writeInfoHeaders( Worksheet $sheet, $data ) {
    $sheet->fromArray( array(
      array( 'Project', $data['project'] ),
      array( 'Sprint', 's1' ),
      array( 'From', $data['from'] ),
      array( 'To', $data['to'] ),
    ), null, $this->getPointer() );
    $this->movePointer( 3 );
  }

  private function writeUser( Worksheet $sheet, $data ) {
    $origin = $this->getPointer();
    $dest   = $this->movePointer( 3 );
    $sheet->mergeCells( $origin . ':' . $dest );
    $sheet->getCell( $origin )->setValue( $dest );
    $this->movePointer( - 3, 1 );

    $this->writeUserLogs();
    $this->writeUserTotals();
    $this->movePointer(4);
  }

  private function writeUserLogs( Worksheet $sheet, $logs ) {
    $origin = $this->getPointer();
    $sheet->fromArray( array( 'Worklog', 'Issue', 'Started', 'Time Spent (h)' ), null, $origin );
    $this->movePointer( 0, 1 );
  }

  private function writeUserLog( Worksheet $sheet, $log ) {

  }

  private function writeUserTotals( Worksheet $sheet, $totals ) {

  }
}