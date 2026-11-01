<?php

/**
 * @phutil-external-symbol class PhpOffice\PhpSpreadsheet\Spreadsheet
 * @phutil-external-symbol class PhpOffice\PhpSpreadsheet\IOFactory
 * @phutil-external-symbol class PhpOffice\PhpSpreadsheet\Cell\Coordinate
 */
final class PhabricatorExcelExportFormat
  extends PhabricatorExportFormat {

  const EXPORTKEY = 'excel';

  private $workbook;
  private $sheet;
  private $rowCursor;

  public function getExportFormatName() {
    return pht('Excel (.xlsx)');
  }

  public function isExportFormatEnabled() {
    if (!extension_loaded('zip')) {
      return false;
    }

    // Loading the optional modern library must be an explicit bootstrap step.
    // Do not discover/install dependencies or enable it by default.
    if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet', false)) {
      return true;
    }

    return @include_once 'PHPExcel.php';
  }

  public function getInstallInstructions() {
    if (!extension_loaded('zip')) {
      return pht(<<<EOHELP
Data can not be exported to Excel because the "zip" PHP extension is not
installed. Consult the setup issue in the Config application for guidance on
installing the extension.
EOHELP
        );
    }

    return pht(<<<EOHELP
Data can not be exported to Excel because an optional spreadsheet library is not
installed. This software component is required to create Excel files.

PhpSpreadsheet may be explicitly preloaded by an administrator:

> https://github.com/PHPOffice/PhpSpreadsheet

Briefly:

  - Install a compatible version in a separately managed dependency directory.
  - Explicitly preload its autoloader and Spreadsheet class in the intended
    PHP process. The format remains disabled without this bootstrap.

Existing PHPExcel installations remain supported through PHP `%s`.
EOHELP
      ,
      'include_path');
  }

  public function getFileExtension() {
    return 'xlsx';
  }

  public function getMIMEContentType() {
    return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
  }

  /**
   * @phutil-external-symbol class PHPExcel_Cell_DataType
   */
  public function addHeaders(array $fields) {
    $sheet = $this->getSheet();

    $header_format = array(
      'font'  => array(
        'bold' => true,
      ),
    );

    $row = 1;
    $col = 0;
    foreach ($fields as $field) {
      $cell_value = $field->getLabel();

      $cell_name = $this->getCellName($col, $row);

      $sheet->setCellValue($cell_name, $cell_value);
      $cell = $sheet->getCell($cell_name);

      $sheet->getStyle($cell_name)->applyFromArray($header_format);
      $cell->setDataType('s');

      $width = $field->getCharacterWidth();
      if ($width !== null) {
        $col_name = $this->getCellName($col);
        $sheet->getColumnDimension($col_name)
          ->setWidth($width);
      }

      $col++;
    }
  }

  public function addObject($object, array $fields, array $map) {
    $sheet = $this->getSheet();

    $col = 0;
    foreach ($fields as $key => $field) {
      $cell_value = $map[$key];
      $cell_value = $field->getPHPExcelValue($cell_value);

      $cell_name = $this->getCellName($col, $this->rowCursor);

      $sheet->setCellValue($cell_name, $cell_value);
      $cell = $sheet->getCell($cell_name);

      $style = $sheet->getStyle($cell_name);
      $field->formatPHPExcelCell($cell, $style);

      $col++;
    }

    $this->rowCursor++;
  }

  /**
   * @phutil-external-symbol class PHPExcel_IOFactory
   */
  public function newFileData() {
    $workbook = $this->getWorkbook();
    if ($workbook instanceof \PhpOffice\PhpSpreadsheet\Spreadsheet) {
      $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($workbook, 'Xlsx');
    } else {
      $writer = PHPExcel_IOFactory::createWriter($workbook, 'Excel2007');
    }

    ob_start();
    $writer->save('php://output');
    $data = ob_get_clean();

    return $data;
  }

  private function getWorkbook() {
    if (!$this->workbook) {
      $this->workbook = $this->newWorkbook();
    }
    return $this->workbook;
  }

  /**
   * @phutil-external-symbol class PHPExcel
   */
  private function newWorkbook() {
    if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet', false)) {
      return new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    }
    include_once 'PHPExcel.php';
    return new PHPExcel();
  }

  private function getSheet() {
    if (!$this->sheet) {
      $workbook = $this->getWorkbook();

      $sheet = $workbook->setActiveSheetIndex(0);
      $sheet->setTitle($this->getTitle());

      $this->sheet = $sheet;

      // The row cursor starts on the second row, after the header row.
      $this->rowCursor = 2;
    }

    return $this->sheet;
  }


  /**
   * @phutil-external-symbol class PHPExcel_Cell
   */
  private function getCellName($col, $row = null) {
    if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet', false)) {
      $col_name = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1);
    } else {
      $col_name = PHPExcel_Cell::stringFromColumnIndex($col);
    }

    if ($row === null) {
      return $col_name;
    }

    return $col_name.$row;
  }

}
