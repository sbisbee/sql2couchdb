#!/usr/bin/php
<?php
/*
  Copyright 2011 Sam Bisbee

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "AS IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
*/

/*
 * sql2couchdb - Proof of Concept
 * by Sam Bisbee <sam@sbisbee.com>
 * on 2010-02-17
 *
 * This file is a hacked up proof of concept that will be turned into an actual
 * program. It is only meant for development, has plenty of horrible code, and
 * you should not try to use it. That being said, its basic functionality does
 * work.
 */

require('sag-0.3.0/Sag.php');

function getColType($colName, $mysqlQuery)
{
  for($i = 0; $i < mysql_num_fields($mysqlQuery); $i++)
  {
    $colMeta = mysql_fetch_field($mysqlQuery, $i);

    if($colMeta->name == $colName)
      return $colMeta->type;
  }

  return null;
}

function castSQLToPHP($mysqlType, $value)
{
  //Leave textual and unknown types alone (defaults to string).

  switch($mysqlType)
  {
    case 'int':         $value = (int) $value; break;
    case 'smallint':    $value = (int) $value; break;
    case 'mediumint':   $value = (int) $value; break;
    case 'bigint':      $value = (int) $value; break;
    case 'integer':     $value = (int) $value; break;

    case 'float':       $value = (float) $value; break;

    case 'double':      $value = (double) $value; break;

    case 'bool':        $value = (bool) $value; break;
    case 'boolean':     $value = (bool) $value; break;
    case 'tinyint':     $value = (bool) $value; break;
    default:            $value = utf8_encode($value); break;
  }

  return $value;
}

function map($row, $json, $mysqlQuery)
{
  $wasObject = false;

  if($json)
  {
    //turn objects into associative arrays
    if(is_object($json))
    {
      $wasObject = true;
      $json = (array) $json;
    }

    if(is_array($json))
    {
      foreach($json as $k => $v)
      {
        $json[$k] = map($row, $v, $mysqlQuery);

        if($k == '_id')
          $json[$k] = (string) $json[$k];
      }
    }
    elseif(is_string($json))
      if($row[$json])
        return castSQLToPHP(getColType($json, $mysqlQuery), $row[$json]);

    if($wasObject)
      $json = (object) $json;
  }

  return $json;
}

// Command line args

$options = getopt(null, 
                  array(
                    "mysql-user:",
                    "mysql-pass:",
                    "mysql-db:",
                    "mysql-host:",
                    "mysql-port:",
                    "couchdb-user:",
                    "couchdb-pass:",
                    "couchdb-db:",
                    "couchdb-host:",
                    "couchdb-port:"
                  )
);

// Default config values

$mysqlConfig = new StdClass();
$mysqlConfig->host = '127.0.0.1';
$mysqlConfig->port = '3306';

$couchdbConfig = new StdClass();
$couchdbConfig->host = '127.0.0.1';
$couchdbConfig->port = '5984';

foreach($options as $opt => $value)
{
  switch($opt)
  {
    case 'mysql-user':
      $mysqlConfig->user = $value;
      break;

    case 'mysql-pass':
      $mysqlConfig->pass = $value;
      break;

    case 'mysql-db':
      $mysqlConfig->db = $value;
      break;

    case 'mysql-host':
      $mysqlConfig->host = $value;
      break;

    case 'mysql-port':
      $mysqlConfig->port = $value;
      break;

    case 'couchdb-user':
      $couchdbConfig->user = $value;
      break;

    case 'couchdb-pass':
      $couchdbConfig->pass = $value;
      break;

    case 'couchdb-db':
      $couchdbConfig->db = $value;
      break;

    case 'couchdb-host':
      $couchdbConfig->host = $value;
      break;

    case 'couchdb-port':
      $couchdbConfig->port = $value;
      break;
  }
}

// Parse and check the JSON file

$jsonFile = json_decode(file_get_contents(array_pop($argv)));

if(!$jsonFile || !is_object($jsonFile))
  throw new Exception('Unexpected JSON format.');

if(!$jsonFile->query || !is_string($jsonFile->query))
  throw new Exception('Missing the SQL statement.');

if(!$jsonFile->doc || !is_object($jsonFile->doc))
  throw new Exception('Missing the doc.');

// Set up Sag now so that we don't run the whole MySQL loop and then end up
// with an error.

$sag = new Sag($couchdbConfig->host, $couchdbConfig->port);
$sag->setDatabase($couchdbConfig->db);

if($couchdbConfig->user || $couchdbConfig->pass)
  $sag->login($couchdbConfig->user, $couchdbConfig->pass);

// Get data from MySQL and send it to CouchDB.

if(!($mysqlCon = mysql_connect("{$mysqlConfig->host}:{$mysqlConfig->port}", $mysqlConfig->user, $mysqlConfig->pass)))
  throw new Exception('Unable to connect to MySQL: '.mysql_error());

if(!mysql_select_db($mysqlConfig->db, $mysqlCon))
  throw new Exception('Unable to select db: '.mysql_error());

if(!($mysqlQuery = mysql_query($jsonFile->query, $mysqlCon)))
  throw new Exception('Invalid query: '.mysql_error());

$docsToSend = array();

$c = 0;
while($row = mysql_fetch_array($mysqlQuery, MYSQL_ASSOC))  {
  $docsToSend[] = map($row, clone $jsonFile->doc, $mysqlQuery);
  $c++;
  if ($c === 1001) {
    $sag->bulk($docsToSend);
    $docsToSend = array();
    $c = 0;
  }
}

if (count($docsToSend) > 0) {
  $sag->bulk($docsToSend);
}

mysql_free_result($mysqlQuery);
mysql_close($mysqlCon);
?>
