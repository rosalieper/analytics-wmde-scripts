<?php

/**
 * @author Amir Sarabadani
 */
class WikimediaDbSectionMapper {

	private $dbMap;

	public function __construct( array $dbMap = null ) {
		$this->dbMap = $dbMap;
	}

	public function getSection( $db = 'wikidatawiki' ) {
		if ( $this->dbMap === null ) {
			$this->loadDbMap();
		}

		if ( !array_key_exists( $db, $this->dbMap ) ) {
			// Default section
			$section = 's3';
		} else {
			$section = $this->dbMap[$db];
		}

		$port = $this->getPortFromSection( $section );
		return [ 'section' => $section, 'port' => $port ];
	}

	private function loadDbMap() {
		$eqiadDbData = WikimediaCurl::curlGetExternal( 'https://noc.wikimedia.org/conf/db-eqiad.php.txt' );

		if( $eqiadDbData === false ) {
			throw new RuntimeException( 'Failed to get db data! (request failed)' );
		}

		$map = [];
		preg_match_all( "/(?<=sectionsByDB' \=>) \[(.+?)\]/s", $eqiadDbData[1], $map );
		eval( '$map = ' . $map[0][0] . ';' );
		$this->dbMap = $map;
	}

	private function getPortFromSection( $section ) {
		return '331' . substr( $section, -1 );
	}

}
