#!/usr/bin/php
<?php
/**
 *
 * @author Addshore
 * Sends data about betafeatures usage to graphite
 * Used by: https://grafana.wikimedia.org/dashboard/db/mediawiki-betafeatures
 */

/**
 * To update this list see wgBetaFeaturesWhitelist in
 * https://noc.wikimedia.org/conf/InitialiseSettings.php.txt
 */
$currentFeatures = array(
	'visualeditor-enable',
	'beta-feature-flow-user-talk-page',
	'uls-compact-links',
	'popups',
	'cx',
	'read-more',
	'cirrussearch-completionsuggester',
	'ores-enabled',
	'revisionslider',
);

require_once( __DIR__ . '/../../lib/load.php' );
Output::startScript( __FILE__ );

$dblist = WikimediaCurl::curlGet( 'https://noc.wikimedia.org/conf/all.dblist' );
if( $dblist === false ) {
	throw new RuntimeException( 'Failed to get db list for beta feature tracking!' );
}
$dbs = explode( "\n", $dblist[1] );
$dbs = array_filter( $dbs );

$pdo = WikimediaDb::getPdo();

$metrics = array();
$tempTableName = 'staging.wmde_analytics_betafeature_users_temp';
$yesterdayTableName = 'staging.wmde_analytics_betafeature_users_yesterday';

// Create temporary table
$sql = "CREATE TEMPORARY TABLE IF NOT EXISTS $tempTableName";
$sql .= "( user_name VARCHAR(255) NOT NULL, feature VARBINARY(255) NOT NULL, PRIMARY KEY (user_name, feature) )";
$queryResult = $pdo->query( $sql );
if ( $queryResult === false ) {
	die( "Failed to create temp table $tempTableName" );
}
// Create yesterday table
$sql = "CREATE TABLE IF NOT EXISTS $yesterdayTableName";
$sql .= "( user_name VARCHAR(255) NOT NULL, feature VARBINARY(255) NOT NULL, PRIMARY KEY (user_name, feature) )";
$queryResult = $pdo->query( $sql );
if ( $queryResult === false ) {
	die( "Failed to create table $yesterdayTableName" );
}

// Loop through all wiki databases
foreach( $dbs as $dbname ) {
	if( $dbname === 'labswiki' || $dbname === 'labtestwiki' ) {
		continue;
	}
	// Aggregate the overall betafeatures_user_counts
	$sql = "SELECT * FROM $dbname.betafeatures_user_counts";
	$queryResult = $pdo->query( $sql );
	if( $queryResult === false ) {
		Output::timestampedMessage( "SELECT 1 failed for $dbname, Skipping!! " );
	} else {
		foreach( $queryResult as $row ) {
			$feature = $row['feature'];
			$number = $row['number'];
			@$metrics[$feature] += $number;
		}
	}

	// Record individuals into the temp table
	foreach( $currentFeatures as $feature ) {
		$sql = "INSERT IGNORE INTO $tempTableName ( user_name, feature )";
		$sql .= " SELECT user_name, up_property FROM $dbname.user_properties";
		$sql .= " JOIN $dbname.user ON up_user = user_id";
		$sql .= " WHERE up_property = '$feature' AND up_value = '1'";
		$queryResult = $pdo->query( $sql );
		if( $queryResult === false ) {
			Output::timestampedMessage( "INSERT INTO FAILED for $dbname for feature $feature, Skipping!!" );
		}
	}
}

// Send total user_counts (1 global user can be counted more than once)
foreach( $metrics as $featureName => $value ) {
	if ( in_array( $featureName, $currentFeatures ) && $value > 0 ) {
		WikimediaGraphite::sendNow( 'daily.betafeatures.user_counts.totals.' . $featureName, $value );
	}
}

// Select and send the global user counts (each global user is only counted once)
$sql = "SELECT COUNT(*) AS count, feature";
$sql .= " FROM $tempTableName";
$sql .= " GROUP BY feature";
$queryResult = $pdo->query( $sql );
if( $queryResult === false ) {
	Output::timestampedMessage( "SELECT FROM temp table $tempTableName FAILED!!" );
} else {
	foreach( $queryResult as $row ) {
		if ( in_array( $row['feature'], $currentFeatures ) && $row['count'] > 0 ) {
		WikimediaGraphite::sendNow(
			'daily.betafeatures.global_user_counts.totals.' . $row['feature'],
			$row['count']
		);
		}
	}
}

// Compare todays data with yesterdays data (if present)
$queryResult = $pdo->query( "SELECT * FROM $yesterdayTableName LIMIT 1" );
if ( $queryResult === false ) {
	Output::timestampedMessage( "FAILED: $sql" );
} else if( count( $queryResult->fetchAll() ) > 0 ) {
	// Work out what has changed between days
	// Emulated INTERSECT: http://stackoverflow.com/a/950505/4746236
	$sql = "SELECT 'enables' AS state, today.* FROM $tempTableName AS today";
	$sql .= " WHERE ROW(today.user_name, today.feature) NOT IN";
	$sql .= " ( SELECT * FROM $yesterdayTableName )";
	$sql .= " UNION ALL";
	$sql .= " SELECT 'disables' AS state, yesterday.* FROM $yesterdayTableName AS yesterday";
	$sql .= " WHERE ROW(yesterday.user_name, yesterday.feature) NOT IN";
	$sql .= " ( SELECT * FROM $tempTableName )";
	$sql = "SELECT state, COUNT(*) AS count, feature FROM ( $sql ) AS a GROUP BY state, feature";
	$queryResult = $pdo->query( $sql );
	if ( $queryResult === false ) {
		Output::timestampedMessage( "FAILED Intersection, Skipping!!" );
	} else {
		foreach( $queryResult as $row ) {
			WikimediaGraphite::sendNow(
				'daily.betafeatures.global_' . $row['state'] . '.totals.' . $row['feature'],
				$row['count']
			);
		}
	}
} else {
	Output::timestampedMessage( "No data contained in yesterdays table, Skipping!!" );
}

// Clear yesterdays table
$sql = "TRUNCATE TABLE $yesterdayTableName";
$queryResult = $pdo->query( $sql );
if( $queryResult === false ) {
	Output::timestampedMessage( "FAILED: $sql" );
}

// Add todays data into the yesterday table
$sql = "INSERT INTO $yesterdayTableName ( user_name, feature )";
$sql .= " SELECT user_name, feature FROM $tempTableName";
$queryResult = $pdo->query( $sql );
if( $queryResult === false ) {
	Output::timestampedMessage( "FAILED: $sql" );
}
