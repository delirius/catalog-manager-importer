<?php

namespace CMImporter;

use CatalogManager\Toolkit as Toolkit;
use CatalogManager\CatalogController as CatalogController;


class CatalogCSVImporter extends CatalogController {


    protected $arrData = [];
    protected $strDelimiter;
    protected $objFile = null;
    protected $strTablename = '';


    public function __construct( $strCSVFilePath, $strTablename, $strDelimiter = '' ) {

        ini_set( 'auto_detect_line_endings', true );

        $this->import( 'Database' );

        $this->strTablename = $strTablename;
        $this->strDelimiter = $strDelimiter ? $strDelimiter : ',';
        $this->objFile = fopen( TL_ROOT . '/' . $strCSVFilePath, 'r' );
    }


    public function readAndGetCSVHeader( $blnKeysOnly = false ) {

        $arrData = fgetcsv( $this->objFile, 0, $this->strDelimiter );

        if ( !is_array( $arrData ) || empty( $arrData ) ) return [];
        if ( $blnKeysOnly ) return $arrData;

        return array_keys( $arrData );
    }


    public function prepareData( $arrMapping, $arrOptions, $blnIgnoreHeader = false ) {

        if ( !is_array( $arrMapping ) || empty( $arrMapping ) ) return null;

        $arrPosition = 0;

        if ( $arrOptions['clearTable'] ) {

            $this->Database->prepare( sprintf( 'DELETE FROM %s', $this->strTablename ) )->execute();
        }

        while ( ( $arrData = fgetcsv( $this->objFile, 0, $this->strDelimiter ) ) !== FALSE ) {

            if ( $blnIgnoreHeader ) {

                $blnIgnoreHeader = false;

                continue;
            };

            $this->arrData[ $arrPosition ] = [];

            foreach ( $arrData as $intIndex => $strValue ) {

                $arrMap = $arrMapping[ $intIndex ];

                if ( isset( $arrMap['continue'] ) && $arrMap['continue'] ) continue;

                $strFieldname = $arrMap['column'] ? $arrMap['column'] : $arrMap['head'];
                $this->arrData[ $arrPosition ][ $strFieldname ] = $this->parseValue( $strValue, $arrMap['type'], $arrOptions );
            }

            $arrPosition++;
        }

        for ( $intIndex = 0; count( $this->arrData ) > $intIndex; $intIndex++ ) {

            if ( !Toolkit::isEmpty( $arrOptions['titleTpl'] ) ) {

                $this->arrData[ $intIndex ]['title'] = \StringUtil::parseSimpleTokens( $arrOptions['titleTpl'], $this->arrData[ $intIndex ]  );
            }

            $this->arrData[ $intIndex ]['tstamp'] = time();
        }
    }


    protected function generareAlias( $varValue, $varValues = [] ) {

        if ( $varValue === '' || $varValue === null ) {

            return md5( time() . uniqid() );
        }

        $varValue = Toolkit::slug( $varValue );
        $objEntity = $this->Database->prepare( 'SELECT * FROM ' . $this->strTablename . ' WHERE `alias` = ?' )->execute( $varValue );

        if ( $objEntity->numRows ) {

            $varValue .= isset( $varValues['id'] ) ? '_' . $varValues['id'] :  '_' . uniqid();
        }

        return $varValue;
    }


    public function saveCsvToDatabase( $strTable, $arrOptions ) {

        foreach ( $this->arrData as $arrValue ) {

            if ( $arrOptions['useAlias'] ) {

                $arrValue['alias'] = $this->generareAlias( $arrValue['title'], $arrValue );
            }

            if ( isset( $GLOBALS['TL_HOOKS']['catalogImporterBeforeSave'] ) && is_array( $GLOBALS['TL_HOOKS']['catalogImporterBeforeSave'] ) ) {

                foreach ( $GLOBALS['TL_HOOKS']['catalogImporterBeforeSave'] as $callback ) {

                    $this->import( $callback[0] );
                    $arrValue = $this->{$callback[0]}->{$callback[1]}( $arrValue, $arrOptions, $this->strTablename, $this );
                }
            }

            if ( is_array( $arrValue ) && !empty( $arrValue ) ) {

                $this->Database->prepare( 'INSERT INTO '. $strTable .' %s' )->set( $arrValue )->execute();
            }
        }
    }


    public function close() {

        fclose( $this->objFile );
        ini_set( 'auto_detect_line_endings', false );
    }


    protected function parseValue( $strValue, $strType, $arrOptions ) {

        $strType = $GLOBALS['CTLG_IMPORT_GLOBALS']['DATA_TYPES'][ $strType ];

        switch ( $strType ) {

            case 'TEXT':

                if ( Toolkit::isEmpty( $strValue ) ) return '';

                $strValue = utf8_encode( $strValue );

                return $strValue;

                break;

            case 'INT':

                if ( Toolkit::isEmpty( $strValue ) ) return 0;

                return intval( $strValue );

                break;

            case 'FILE':

                if ( Toolkit::isEmpty( $strValue ) ) return '';

                $strPath = $arrOptions['filesFolder'] . '/' . $strValue;
                $strPath = strval( str_replace( ' ', '', $strPath ) );

                if ( file_exists( $strPath ) ) {

                    $objFile = \FilesModel::findByPath( $strPath );

                    if ( $objFile === null ) {

                        \System::log( sprintf( 'File "%s" do not exist in tl_files table', $strPath ), __METHOD__, TL_GENERAL );

                        return '';
                    }

                    return $objFile->uuid;
                }

                \System::log( sprintf( 'File "%s" do not exist', $strPath ), __METHOD__, TL_GENERAL );

                return '';

                break;

            case 'DATE':

                if ( Toolkit::isEmpty( $strValue ) ) return 0;

                try {

                    $objDate = new \Date( $strValue, $arrOptions['datimFormat'] );

                    return $objDate->tstamp;
                }

                catch ( \Exception $objError ) {

                    \System::log( $objError->getMessage(), __METHOD__, TL_GENERAL );
                }

                return 0;

                break;
        }

        return '';
    }
}