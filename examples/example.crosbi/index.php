<?php

namespace Crosbi;


use oaiprovider\xml\NS;

set_include_path('/usr/local/src/oai2-php');

require_once './config.crosbi.php';
require_once 'endpoint.php';
require_once 'xml/europeana.php';
require_once 'xml/dublincore.php';

use oaiprovider\xml\EuropeanaRecord;
use oaiprovider\xml\DublinCoreRecord;
use oaiprovider\Repository;
use oaiprovider\Header;

date_default_timezone_set('Europe/Zagreb');

class CrosbiRepository implements Repository {
  
  public function getIdentifyData() {
    return array(
        'repositoryName' => 'CROSBI OAI Provider',
        'baseURL' => 'http://31.147.204.58/oai2/',
        'protocolVersion' => '2.0',
        'adminEmail' => 'alen@irb.hr',
        'earliestDatestamp' => '1970-01-01T00:00:00Z',
        'deletedRecord' => 'persistent',
        'granularity' => 'YYYY-MM-DDThh:mm:ssZ');
  }
  
  public function getMetadataFormats($identifier = null) {
  	// In this example every item supports both formats, so we can ignore
  	// the parameter identifier.
    return array(
        array(
            'metadataPrefix' => 'oai_dc',
            'schema' => 'http://www.openarchives.org/OAI/2.0/oai_dc.xsd',
            'metadataNamespace' => 'http://www.openarchives.org/OAI/2.0/oai_dc/',
        ),
        array(
            'metadataPrefix' => 'ese',
            'schema' => 'http://www.europeana.eu/schemas/ese/ESE-V3.3.xsd',
            'metadataNamespace' => 'http://www.europeana.eu/schemas/ese/',
        ),
    );
  }

  public function getSets() {
    return array(
        array('spec' => 'Znanstveni' , 'name' => 'Research paper'),
        array('spec' => 'Strucni' , 'name' => 'Professional paper'),
        array('spec' => 'Pregledni' , 'name'  => 'Review'),
        array('spec' => 'Ostalo' , 'name' => 'Other')
    );
  }

  public function getIdentifiers($from, $until, $set, $last_identifier, $max_results) {

    $sql = "SELECT id FROM casopis";

    $where = array();
    if ($from) {
      $where[] = "time_date >= " . DB::quote(date('Y-m-d H:i:s', $from));
    }
    if ($until) {
      $where[] = "time_date <= " . DB::quote(date('Y-m-d H:i:s', $until));
    }
    if ($set) {
      $where[] = "kategorija = " . DB::quote($set);
    }
    if ($last_identifier) {
      $where[] = "id > " . DB::quote($last_identifier);
    }

    if (count($where)) {
      $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY id ASC";

    if ($max_results) {
      $sql .= " LIMIT " . $max_results;
    }
    
    $ids = array();


	error_log('ERROR:' . $sql);

    foreach(DB::query($sql) as $row) {
      $ids[] = $row['id'];
    }
    return $ids;
  }

  public function getHeader($identifier) {
    //$row = DB::fetchRow("SELECT id, lastchanged, category, deleted FROM books WHERE id=" . DB::quote($identifier));
    $row = DB::fetchRow("SELECT id, time_date, kategorija, deleted FROM casopis WHERE id=" . DB::quote($identifier));
    
    if ($row == null) {
    	return null;
    }
    
    $header = new Header();
    $header->identifier = OAI_REPO_ID . $row['id'];
    $header->datestamp = strtotime($row['time_date']);
    if ($row['kategorija']) {
      $header->setSpec = array($row['kategorija']);
    }
    $header->deleted = ($row['deleted'] == 1);
    return $header;
  }

  public function getMetadata($metadataPrefix, $identifier) {
    $row = DB::fetchRow("SELECT * FROM casopis WHERE id=" . DB::quote($identifier));
	error_log("SELECT * FROM casopis WHERE id=" . DB::quote($identifier));
    if ($row['deleted'] == 1) {
      return null;
    }

    $creators = explode(";", $row['autori']);

    switch($metadataPrefix) {
      case 'oai_dc':
        $dcrec = new DublinCoreRecord();

        $dcrec->addNS(NS::DC, 'relation', OAI_RELATION_ROOT . $identifier);

        $dcrec->addNS(NS::DC, 'title', $row['naslov']);

	foreach ($creators as $creator) {
        	$dcrec->addNS(NS::DC, 'creator', trim($creator));
	}

	if (isset($row['sazetak']))
        	$dcrec->addNS(NS::DC, 'description', $row['sazetak']);

	if (isset($row['kljucne_rijeci']))
        	$dcrec->addNS(NS::DC, 'subject', $row['kljucne_rijeci']);

	if (isset($row['kategorija']))
               $dcrec->addNS(NS::DC, 'type', $row['kategorija']);

	if (isset($row['vrsta_rada']))
                $dcrec->addNS(NS::DC, 'type', $row['vrsta_rada']);

	if (isset($row['jezik']))
                $dcrec->addNS(NS::DC, 'language', $row['jezik']);

	if (isset($row['datoteka']))
                $dcrec->addNS(NS::DC, 'identifier', 'http://bib.irb.hr/datoteka/' . $row['datoteka']);

	if (isset($row['doi']) && $row['doi'] != '')
                $dcrec->addNS(NS::DC, 'relation', $row['doi']);

	if (isset($row['casopis']))
                $dcrec->addNS(NS::DC, 'relation', $row['casopis'] . ' [ISSN: ' . $row['issn'] . ']');

	if (isset($row['time_date']))
                $dcrec->addNS(NS::DC, 'date', $row['time_date']);

        return $dcrec->toXml();

      case 'ese':
        $eserec = new EuropeanaRecord();
        $eserec->addNS(NS::DC, 'title', $row['title']);
	if (isset($row['description']))
        	$eserec->addNS(NS::DC, 'description', $row['description']);
        return $eserec->toXml();
    }
  }
  
}

class DB {
  
  static function getConnection() {
    static $conn;
    if ($conn == null) {
      $conn = new \PDO(DB_DSN, DB_USER, DB_PASS);
    }
    return $conn;
  }
  
  static function fetchRow($sql) {
    foreach(self::query($sql) as $row) {
      return $row;
    }
    return null;
  }
  
  static function query($sql) {
    $result = self::getConnection()->query($sql);
    if (!$result) {
      //throw new Exception(self::getConnection()->errorInfo());
    }
    return $result;
  }
  
  static function quote($val) {
    return self::getConnection()->quote($val);
  }
}


\oaiprovider\handleRequest($_GET, new CrosbiRepository);
