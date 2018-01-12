<?php

namespace AppBundle\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Tcpdf;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportService {

  private $pointer;
  private $server;

  public function getFile( $data, $format = 'xls' ) {
    $this->server  = $data['server'];
    $this->pointer = array( 1, 1 );
    $spreadsheet   = new Spreadsheet();
    $sheet         = $spreadsheet->getActiveSheet();
    $sheet->setTitle( 'Log report' );
    $this->writeInfoHeaders( $sheet, $data );
    foreach ( $data['logs']['teamLogs'] as $user => $logs ) {
      $this->writeUser( $sheet, $user, $logs, $data['logs']['teamTotals'][ $user ] );
    }

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
      array( 'Sprint', ( $data['sprint'] ) ? $data['sprint']->name : '-' ),
      array( 'From', $data['from']->format( 'd/m/Y' ) ),
      array( 'To', $data['to']->format( 'd/m/Y' ) ),
    ), null, $this->getPointer() );
    $this->movePointer( 3 );
  }

  private function writeUser( Worksheet $sheet, $user, $logs, $total ) {
    $origin = $this->getPointer();
    $dest   = $this->movePointer( 3 );
    $sheet->mergeCells( $origin . ':' . $dest );
    $sheet->getCell( $origin )->setValue( $user );
    $this->movePointer( - 3, 1 );

    $this->writeUserLogs( $sheet, $logs );
    $this->writeUserTotals( $sheet, $total );
    $this->movePointer( 5, - ( count( $logs ) + 3 ) );
  }

  private function writeUserLogs( Worksheet $sheet, $logs ) {
    $origin = $this->getPointer();
    $sheet->fromArray( array( 'Worklog', 'Issue', 'Started', 'Time (h)' ), null, $origin );
    $origin = $this->movePointer( 0, 1 );
    foreach ( $logs as $log ) {
      $this->writeUserLog( $sheet, $log );
    }
  }

  private function writeUserLog( Worksheet $sheet, $log ) {
    $origin = $this->getPointer();
    $sheet
      ->getCell( $origin )
      ->setValue( $log->id )
      ->getHyperlink()
      ->setUrl( $this->getWorklogLink( $log ) );

    $origin = $this->movePointer( 1 );

    $sheet
      ->getCell( $origin )
      ->setValue( $log->issue->key )
      ->getHyperlink()
      ->setUrl( $this->getIssueLink( $log->issue->key ) );

    $origin = $this->movePointer( 1 );

    $sheet
      ->getCell( $origin )
      ->setValue( $log->started->format( 'Y-m-d H:i' ) );

    $origin = $this->movePointer( 1 );

    $sheet
      ->getCell( $origin )
      ->setValue( floatval( $log->timeSpentSeconds ) / 60 / 60 );

    $this->movePointer( - 3, 1 );
  }

  private function writeUserTotals( Worksheet $sheet, $total ) {
    $origin = $this->getPointer();
    $dest   = $this->movePointer( 2 );
    $sheet->mergeCells( $origin . ':' . $dest );
    $sheet->getCell( $origin )->setValue( 'Total' );
    $origin = $this->movePointer( 1 );
    $sheet->getCell( $origin )->setValue( floatval( $total ) / 60 / 60 );
    $this->movePointer( - 3, 1 );
  }

  private function getWorklogLink( $worklog ) {
    return $this->server->getBaseUrl() . '/browse/' . $worklog->issue->key . '?focusedWorklogId=' . $worklog->id .
           '&page=com.atlassian.jira.plugin.system.issuetabpanels%3Aworklog-tabpanel#worklog-' . $worklog->id;
  }

  private function getIssueLink( $issueKey ) {
    return $this->server->getBaseUrl() . '/browse/' . $issueKey;
  }
}