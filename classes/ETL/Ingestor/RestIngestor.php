<?php
/* ==========================================================================================

 * REST service ingestor. Supports querying a REST endpoing and following previous/next urls in the
 * response and generatig requests based on the results of a query to the data warehouse.
 * Transformation and verificaiton of parameters and results is supported.
 *
 * @author Steve Gallo <smgallo@buffalo.edu>
 * @date 2016-02-05
 * ------------------------------------------------------------------------------------------
 */

namespace ETL\Ingestor;

use ETL\DataEndpoint\Rest;
use ETL\DataEndpoint\aRdbmsEndpoint;
use ETL\EtlConfiguration;
use ETL\EtlOverseerOptions;
use ETL\DbEntity\Query;
use ETL\aOptions;
use ETL\iAction;
use ETL\Utilities;

use \Log;
use \PDO;

class RestIngestor extends aIngestor
implements iAction
{
    // Parsed configuration options for REST request handling
    protected $restRequestConfig = null;

    // Parsed configuration options for REST response handling
    protected $restResponseConfig = null;

    // Column names for the destination table
    protected $destinationTableColumnNames = null;

    // Optional source query for additional parameters
    protected $etlSourceQuery = null;
    protected $etlSourceQueryResult = null;

    // Optional parameters for the rest call
    protected $restParameters = array();

    // The current url, useful for debugging
    private $currentUrl = null;

    // List of transformation and verificaiton directives to apply to request parameters. Keys are
    // parameter names and values are an object containing the directives.
    private $parameterDirectives = array();

    // List of transformation and verificaiton directives to apply to request response fields. Keys
    // are response keys (not database column names) and values are an object containing the
    // directives.
    private $responseDirectives = array();

    // This action does not (yet) support multiple destination tables. If multiple destination
    // tables are present, store the first here and use it.
    protected $etlDestinationTable = null;

    /* ------------------------------------------------------------------------------------------
     * Set up data endpoints and other options.
     *
     * @param IngestorOptions $options Options specific to this Ingestor
     * @param EtlConfiguration $etlConfig Parsed configuration options for this ETL
     * ------------------------------------------------------------------------------------------
     */

    public function __construct(aOptions $options, EtlConfiguration $etlConfig, Log $logger = null)
    {
        parent::__construct($options, $etlConfig, $logger);

        $this->sourceEndpoint = $etlConfig->getDataEndpoint($this->options->source);

        if ( ! $this->sourceEndpoint instanceof Rest ) {
            $this->sourceEndpoint = null;
            $msg = "Source endpoint is not an instance of ETL\\DataEndpoint\\Rest";
            $this->logAndThrowException($msg);
        }
        $this->logger->debug("Source endpoint: " . $this->sourceEndpoint);
        $this->sourceEndpoint->connect();
        $this->sourceHandle = $this->sourceEndpoint->getHandle();

        $this->utilityEndpoint = $etlConfig->getDataEndpoint($this->options->utility);
        $this->utilityEndpoint->connect();

        if ( ! $this->utilityEndpoint instanceof aRdbmsEndpoint ) {
            $this->utilityEndpoint = null;
            $msg = "Source endpoint is not an instance of ETL\\DataEndpoint\\aRdbmsEndpoint";
            $this->logAndThrowException($msg);
        }
        $this->utilityHandle = $this->utilityEndpoint->getHandle();

    }  // __construct()

    /* ------------------------------------------------------------------------------------------
     * @see iAction::verify()
     * ------------------------------------------------------------------------------------------
     */

    protected function initialize()
    {
        if ( $this->isInitialized() ) {
            return;
        }

        $this->initialized = false;

        parent::initialize();

        // This action only supports 1 destination table so use the first one and log a warning if
        // there are multiple.

        reset($this->etlDestinationTableList);
        $this->etlDestinationTable = current($this->etlDestinationTableList);
        $etlTableKey = key($this->etlDestinationTableList);
        if ( count($this->etlDestinationTableList) > 1 ) {
            $msg = $this . " does not support multiple ETL destination tables, using first table with key: '$etlTableKey'";
            $logger->warning($msg);
        }

        // If the source query is specified in the definition file use it to obtain parameters for the
        // rest call. For each record returned by the source query, add the returned columnms to the
        // parameter list and generate one rest call. THIS OVERRIDES THE NEXT/PREV KEYS IN THE RESPONSE!

        if ( null === $this->etlSourceQuery && isset($this->parsedDefinitionFile->source_query) ) {
            $this->logger->info("Create ETL source query object");
            $this->etlSourceQuery = new Query($this->parsedDefinitionFile->source_query,
                                              $this->utilityEndpoint->getSystemQuoteChar());

            // If supported by the source query, set the date ranges here

            $startDate = $this->utilityEndpoint->quote($this->etlOverseerOptions->getCurrentStartDate());
            $endDate = $this->utilityEndpoint->quote($this->etlOverseerOptions->getCurrentEndDate());

            $this->etlSourceQuery->setOverseerRestriction(Query::RESTRICT_START_DATE, $startDate);
            $this->etlSourceQuery->setOverseerRestriction(Query::RESTRICT_END_DATE, $endDate);

        }  // if ( null === $this->etlSourceQuery && isset($this->parsedDefinitionFile->source_query) )

        // Set up some default values for the REST response config. These can be overriden.

        $defaultRestResponseConfig = (object) array(
            // Optional top-level entry point into the result. NSF api uses "response".
            "response" => null,
            // Optional count for the number of results returned
            "count"    => "count",
            // Property for the results list
            "results"  => "results",
            // Optional property to identify the next page of results
            "next"     => "next",
            // Optional property to identify the previous page of results
            "prev"     => "previous",
            // Error response handling
            "error"    => null
            );

        if ( null === $this->restResponseConfig && isset($this->parsedDefinitionFile->rest_response) ) {
            $this->restResponseConfig = (object) array_merge((array) $defaultRestResponseConfig,
                                                             (array) $this->parsedDefinitionFile->rest_response);
        } else if ( ! isset($this->parsedDefinitionFile->rest_response) ) {
            $msg = "rest_response key not found in definition file";
            $this->logAndThrowException($msg);
        }

        if ( null === $this->restRequestConfig && isset($this->parsedDefinitionFile->rest_request) ) {
            $this->restRequestConfig = $this->parsedDefinitionFile->rest_request;
        } else if ( ! isset($this->parsedDefinitionFile->rest_response) ) {
            $msg = "rest_request key not found in definition file";
            $this->logAndThrowException($msg);
        }

        // --------------------------------------------------------------------------------
        // Create the list of supported macros. Macros starting with a colon (:) are PDO bind
        // paramaters passed in the loop of dirty date ids. If this list is modified, be sure to update
        // the documentation!

        $this->variableMap["UTILITY_SCHEMA"] = $this->utilityEndpoint->getSchema();

        // --------------------------------------------------------------------------------
        // The values for the request parameter and result field map configuration may be an object
        // containing transformation and verification directives.  Separate the directives out into
        // their own lists, leaving the parameters and field_map as simple key-value pairs.

        if ( isset($this->restRequestConfig->parameters) ) {
            foreach ( $this->restRequestConfig->parameters as $parameter => &$value ) {
                if ( ! is_object($value) ) {
                    continue;
                }
                if ( ! isset($value->value) ) {
                    $msg = "{$this} Parameter '$parameter' object does not specify a 'value' key, skipping";
                    $this->logger->warning($msg);
                    continue;
                }
                $this->parameterDirectives[$parameter] = $value;
                $value = $value->value;
            }
            unset($value); // Sever the reference with the last element

        }  // if ( isset($this->restRequestConfig->parameters) )

        if ( isset($this->restResponseConfig->field_map) ) {
            foreach ( $this->restResponseConfig->field_map as $key => &$value ) {
                if ( ! is_object($value) ) {
                    continue;
                }
                if ( ! isset($value->name) ) {
                    $msg = "{$this} Response field map '$key' object does not specify a 'name' key, skipping";
                    $this->logger->warning($msg);
                    continue;
                }
                // Use the response field name as the key so we can make easy lookups in the response object
                $this->responseDirectives[$value->name] = $value;
                $value = $value->name;
            }
            unset($value); // Sever the reference with the last element
        }  // if ( isset($this->restRequestConfig->field_map) )

        $this->initialized = true;

        return true;

    }  // initialize()

    /* ------------------------------------------------------------------------------------------
     * @see iAction::verify()
     * ------------------------------------------------------------------------------------------
     */

    public function verify(EtlOverseerOptions $etlOptions = null)
    {
        if ( $this->isVerified() ) {
            return;
        }

        $this->verified = false;
        if ( null !== $etlOptions ) {
            $this->etlOverseerOptions = $etlOptions;
        }

        $this->initialize();

        parent::verify();

        if ( null !== $this->restRequestConfig && ! is_object($this->restRequestConfig) ) {
            $msg = "REST request config must be an object";
            $this->logAndThrowException($msg);
        } else if ( null !== $this->restResponseConfig && ! is_object($this->restResponseConfig) ) {
            $msg = "REST response config must be an object";
            $this->logAndThrowException($msg);
        }

        // Verify that any type formatting directives in the request and response are valid

        foreach ( $this->parameterDirectives as $parameter => $directives ) {
            $this->verifyDirectives($parameter, $directives);
        }  // if ( isset($this->restRequestConfig->parameters) )

        foreach ( $this->responseDirectives as $key => $directives ) {
            $this->verifyDirectives($key, $directives);
        }  // if ( isset($this->restRequestConfig->field_map) )

        $this->verified = true;

        return true;

    }  // verify()

    /* ------------------------------------------------------------------------------------------
     * @see aIngestor::performPreExecuteTasks()
     * ------------------------------------------------------------------------------------------
     */

    protected function performPreExecuteTasks()
    {
        // ------------------------------------------------------------------------------------------
        // This is not yet updated to fully support multiple ETL destination tables.

        try {

            // Bring the destination table in line with the configuration if necessary.
            // manageTable() is DRYRUN aware.

            $this->manageTable($this->etlDestinationTable, $this->destinationEndpoint);

        } catch ( Exception $e ) {
            $msg = "Error managing ETL table for " . $this->getName() . ": " . $e->getMessage();
            $this->logAndThrowException($msg);
        }

        $this->destinationTableColumnNames = $this->etlDestinationTable->getColumnNames();

        // If the field map is set, ensure that all destination colums exist in the table

        if ( isset($this->restResponseConfig->field_map) ) {
            $diff = array_diff(array_keys((array) $this->restResponseConfig->field_map),
                               $this->destinationTableColumnNames);
            if ( 0 != count($diff) ) {
                $msg = "Field map includes columns not in destination table: " . implode(",", $diff);
                $this->logAndThrowException($msg);
            }
        }  // if ( isset($this->restResponseConfig->field_map) )

        // If using a source query, execute it and prepare the result set

        if ( null !== $this->etlSourceQuery ) {

            $sql = $this->etlSourceQuery->getSelectSql();
            if ( null !== $this->variableMap ) {
                $sql = Utilities::substituteVariables($sql, $this->variableMap);
            }

            $this->logger->debug("REST source query:\n$sql");
            $this->etlSourceQueryResult = $this->utilityHandle->query($sql, array(), true);

            if ( 0 == $this->etlSourceQueryResult->rowCount() ) {
                $msg = "Source query return 0 rows, exiting";
                $this->logger->warning($msg);
                return false;
            }
        }  // if ( null !== $this->etlSourceQuery ) {

        // Verify all directives prior to executing the main body. This keeps the apply() functions
        // leaner.

        $this->verifyDirectives();

        // Apply any parameters that are defined

        $this->processParameters();

        if ( "myisam" == strtolower($this->etlDestinationTable->getEngine()) ) {
            // Disable keys for faster inserts
            $qualifiedDestTableName = $this->etlDestinationTable->getFullName();
            $sqlList = array("ALTER TABLE $qualifiedDestTableName DISABLE KEYS");
            $this->executeSqlList($sqlList, $this->destinationHandle) ;
        }

        return true;

    }  // performPreExecuteTasks()

    /* ------------------------------------------------------------------------------------------
     * @see aIngestor::performPostExecuteTasks()
     * ------------------------------------------------------------------------------------------
     */

    protected function performPostExecuteTasks($numRecordsProcessed)
    {
        if ( "myisam" == strtolower($this->etlDestinationTable->getEngine()) ) {
            $qualifiedDestTableName = $this->etlDestinationTable->getFullName();
            $sqlList = array("ALTER TABLE $qualifiedDestTableName ENABLE KEYS");
            $this->executeSqlList($sqlList, $this->destinationHandle);
        }

        return true;
    }  // performPostExecuteTasks()

    /* ------------------------------------------------------------------------------------------
     * @see aIngestor::_execute()
     * ------------------------------------------------------------------------------------------
     */

    protected function _execute()
    {
        // Support a source query, mapping from the source to rest parameters, rest field map

        // Set up properties used to access data in the result set. Some properties may not be provided.

        $responseKey = ( isset($this->restResponseConfig->response) ? $this->restResponseConfig->response : null );
        $errorKey = ( isset($this->restResponseConfig->error) ? $this->restResponseConfig->error : null );
        $countKey = ( isset($this->restResponseConfig->count) ? $this->restResponseConfig->count : null );
        $resultsKey = ( isset($this->restResponseConfig->results) ? $this->restResponseConfig->results : null );
        $nextKey = ( isset($this->restResponseConfig->next) ? $this->restResponseConfig->next : null );
        $prevKey = ( isset($this->restResponseConfig->prev) ? $this->restResponseConfig->prev : null );
        $fieldMap = ( isset($this->restResponseConfig->field_map) ? (array) $this->restResponseConfig->field_map : null );

        $reservedKeys = array_filter(array($countKey, $resultsKey, $nextKey, $prevKey),
                                     function ($value) {
                                         return ( null !== $value );
                                     } );

        // --------------------------------------------------------------------------------
        // Perform a-priori verifications

        $timeStart = microtime(true);

        // If using a source query, set parameters for the current result.

        if ( null !== $this->etlSourceQuery && null !== $this->etlSourceQueryResult ) {
            $row = $this->etlSourceQueryResult->fetch(PDO::FETCH_ASSOC);
            foreach( $row as $k => $v ) {
                $this->setParameter($k, $v);
            }
        }

        // --------------------------------------------------------------------------------
        // Retrieve and process the REST results

        $this->setRestUrlWithParameters();

        // Keep the current url for logging
        $this->currentUrl = curl_getinfo($this->sourceHandle, CURLINFO_EFFECTIVE_URL);

        $this->logger->info("REST url: {$this->currentUrl}");

        if ( $this->etlOverseerOptions->isDryrun() ) {
            return 0;
        }

        $numRecordsProcessed = 0;
        $numRequestsMade = 1;
        $logCount = 10000;
        $first = true;

        while ( false !== ( $retval = curl_exec($this->sourceHandle) ) ) {

            if ( 0 !== curl_errno($this->sourceHandle) ) {
                $msg = "Error during REST call: " . curl_error($this->sourceHandle);
                $this->logger->err($msg);
                break;
            }

            $response = json_decode($retval);

            if ( null === $response || ! is_object($response) ) {
                $msg = "Response is not an object: $retval";
                $this->logger->err($msg);
                break;
            }

            // --------------------------------------------------------------------------------
            // Identify the various parts of the response based on the configuration and verify them

            // If a top level response key is provided, grab the data that it contains. The NSF award
            // search API uses this.

            if ( $responseKey !== null ) {
                if ( ! isset($response->$responseKey) ) {
                    if ( isset($response->$errorKey) ) {
                        $this->logger->warning("Error querying {$this->currentUrl}");
                        $this->logger->warning("Error response: " . print_r($response->$errorKey, true));
                        if ( false === $this->setNextUrl($response, $nextKey) ) {
                            break;
                        }
                        continue;
                    } else {
                        $msg = "Configured top-level response key '$responseKey' not found in response. " .
                            "Response keys are '" . implode(",", array_keys((array) $response)) . "'";
                        $this->logAndThrowException($msg);
                    }
                } else {
                    $response = $response->$responseKey;
                }
            }  // if ( $responseKey !== null )

            // If a results key was specified, grab the response under that key.

            if ( null !== $resultsKey ) {
                if ( ! isset($response->$resultsKey) ) {
                    if ( isset($response->$errorKey) ) {
                        $this->logger->warning("Error querying {$this->currentUrl}");
                        $this->logger->warning("Error response: " . print_r($response->$errorKey, true));
                        if ( false === $this->setNextUrl($response, $nextKey) ) {
                            break;
                        }
                        continue;
                    } else {
                        $msg = "Configured results key '$resultsKey' not found in response. " .
                            "Response keys are '" . implode(",", array_keys((array) $response)) . "'\n" . print_r($response, true);
                        $this->logAndThrowException($msg);
                    }
                } else {
                    $results = $response->$resultsKey;
                }
            } else {
                $results = $response;
            }

            $count = ( null !== $countKey && isset($response->$countKey) ? $response->$countKey : null );

            // We assume that the response is an array of results, even if it is a single result.

            if ( ! is_array($results) ) {
                $msg = "Request results is expected to be an array. Type returned was " . gettype($results);
                $this->logAndThrowException($msg);
            } else if ( 0 == count($results) ) {
                $msg = "Request returned an empty result set, skipping. url = {$this->currentUrl}";
                $this->logger->notice($msg);

                if ( false === $this->setNextUrl($response, $nextKey) ) {
                    break;
                }
                continue;
            }  // else ( 0 == count($results) )

            // --------------------------------------------------------------------------------
            // Perform some validation on the first pass through the result set.

            if ( $first ) {

                if ( null !== $count ) {
                    $this->logger->info("Ingesting $count records");
                }

                // On the first pass through, check the fields returned to be sure that they map to the
                // destination table columns. If the field map is not provided assume that the field names
                // are all keys in the response.

                $resultKeyNames = array_keys((array) $results[0]);
                if ( null === $fieldMap ) {
                    $diff = array_diff($resultKeyNames, $this->destinationTableColumnNames);
                    if ( 0 != count($diff) ) {
                        $msg = "Result missing keys found in destination table: " .
                            implode(",", $diff);
                        $this->logAndThrowException($msg);
                    }
                }

                /*
                  $diff = ( null === $fieldMap
                  ? array_diff($resultKeyNames, $this->destinationTableColumnNames)
                  : array_diff(array_values($fieldMap), $resultKeyNames) );

                  if ( 0 != count($diff) ) {
                  $msg = "Result missing keys found in " .
                  ( null === $fieldMap ? "destination table" : "field map") . ": " .
                  implode(",", $diff);
                  $this->logAndThrowException($msg);
                  }
                */

                // Create a mapping of result fields to database columns using the field map if provided or
                // the result keys otherwise. A field map is recommended.

                $columnToResultFieldMap = ( null !== $fieldMap
                                            ? $fieldMap
                                            : array_fill_keys($resultKeyNames, $resultKeyNames) );
                $numColumns = count($columnToResultFieldMap);

                $first = false;

            }  // if ( $first )

            // --------------------------------------------------------------------------------
            // Process each result

            $recordCounter = 0;
            $valueList = array();  // PDO bind variables for the query
            $queryParameters = array();  // PDO bind variables to value mapping for the query

            foreach ( $results as $result ) {

                $recordParameters = array();

                // Potentially re-format the results if any processing directives were specified in the
                // field map.

                if ( 0 != count($this->responseDirectives) ) {
                    foreach ( $this->responseDirectives as $resultKey => $directives ) {
                        if ( isset($result->$resultKey) ) {
                            try {
                                $result->$resultKey = $this->applyDirectives($result->$resultKey, $directives);
                            } catch ( Exception $e ) {
                                // If a directive failed skip this result. The exception should have already been
                                // logged.
                                continue;
                            }
                        }
                    }
                }  // if ( 0 != count( $this->responseDirectives) )

                // Build the database query parameters for this record by mapping the response keys to the
                // correct database columns. What's really important is to match the order of the values
                // with the columns in the insert's VALUE clause because the order of records returned can
                // be arbitrary.

                foreach ( $columnToResultFieldMap as $dbCol => $resultKey ) {
                    if ( ! isset($result->$resultKey) ) {
                        // We should add a "if_missing" processing directive here -smg
                        $result->$resultKey = null;
                    }
                    $recordParameters[":{$dbCol}_{$recordCounter}"] = $result->$resultKey;
                }

                if ( $numColumns != count($recordParameters) ) {
                    $msg = "Record counts do not match (expected $numColumns but receieved " . count($recordParameters) .
                        "). url = {$this->currentUrl}";
                    $this->logger->warning($msg);
                }

                $valueList[] = "(" . implode(", ", array_keys($recordParameters)) . ")";
                $queryParameters = array_merge($queryParameters, $recordParameters);
                $recordCounter++;

            }  // foreach ( $results as $result )

            reset($this->etlDestinationTableList);
            $qualifiedDestTableName = current($this->etlDestinationTableList)->getFullName();

            // The SQL can get long if processing a large result set so only display a small portion
            $debugSql = "REPLACE INTO $qualifiedDestTableName (" .
                implode(", ", array_keys($columnToResultFieldMap)) .
                ") VALUES\n" . $valueList[0] . "\n...";

            $this->logger->debug($debugSql);

            $sql = "REPLACE INTO $qualifiedDestTableName (" .
                implode(", ", array_keys($columnToResultFieldMap)) .
                ") VALUES\n" . implode(",\n", $valueList);

            $this->destinationHandle->execute($sql, $queryParameters);

            $numRecordsProcessed += count($results);

            if ( 0 == $numRecordsProcessed % $logCount ) {
                $time = round(microtime(true) - $timeStart, 2);
                $this->logger->info("Processed $numRecordsProcessed records ({$time}s per $logCount)");
                $timeStart = microtime(true);
            }

            // Set up the next url using the "next" key or the source query values

            if ( false === $this->setNextUrl($response, $nextKey) ) {
                break;
            }

            $numRequestsMade++;

        }  // while ( false !== ( $retval = curl_exec($this->sourceHandle) ) )

        if ( 0 != curl_errno($this->sourceHandle) ) {
            $this->logAndThrowException(curl_error());
        }

        $this->logger->info("Made $numRequestsMade REST requests");

        return $numRecordsProcessed;

    }  // _execute()

    /* ------------------------------------------------------------------------------------------
     * The REST ingestor supports request parameters specified in the definition file. Process these
     * parameters, including any macros and add them to the parameter list.
     *
     * @return The number of parameters processed
     *
     * @throw Exception If a parameter was not formatted correctly
     * ------------------------------------------------------------------------------------------
     */

    protected function processParameters()
    {
        $numParameters = 0;

        if ( null === $this->restRequestConfig ||
             ! isset($this->restRequestConfig->parameters) )
        {
            return $numParameters;
        }

        foreach( $this->restRequestConfig->parameters as $parameter => $value ) {

            $value = Utilities::substituteVariables($value, $this->variableMap);
            $this->setParameter($parameter, $value);
            $numParameters++;

        }  // foreach( $this->restRequestConfig->parameters as $parameter => $value )

        return $numParameters;

    }  // processParameters()

    /* ------------------------------------------------------------------------------------------
     * Set an individual rest parameter.
     *
     * @param $parameter The parameter name
     * @param $value The parameter value
     *
     * @return This object for method chaining.
     * ------------------------------------------------------------------------------------------
     */

    protected function setParameter($parameter, $value)
    {
        if ( null === $parameter || empty($parameter) ) {
            $msg = "REST parameter name not provided";
            $this->logAndThrowException($msg);
        }

        $this->restParameters[$parameter] = $value;
        return $this;

    }  // setParameter()

    /* ------------------------------------------------------------------------------------------
     * Format request parameters and add them to the base url.  The format can be a standard querys
     * tring format or a format can be specified in the configuration. Macro substitution is supported
     * in both the parameters and the format.  A special ${REMAINING} macro is supported using the
     * format string that will evaluate to a query string containing any macros that were not used in
     * the format.
     *
     * @return This object for method chaining.
     * ------------------------------------------------------------------------------------------
     */

    protected function setRestUrlWithParameters()
    {
        if ( 0 == count($this->restParameters) ) {
            return;
        }

        // Apply any parameter transform/verify directives prior to setting the url.

        if ( 0 != count( $this->parameterDirectives) ) {
            foreach ( $this->parameterDirectives as $parameter => $directives ) {
                if ( ! array_key_exists($parameter, $this->restParameters) ) {
                    continue;
                }
                try {
                    $this->restParameters[$parameter] = $this->applyDirectives($this->restParameters[$parameter], $directives);
                } catch ( Exception $e ) {
                    $msg = "Parameter '$parameter' (" . $this->restParameters[$parameter] . ") failed processing directives, skipping.";
                    $this->logger->err($msg);
                    return false;
                }
            }
        }  // if ( 0 != count( $this->responseDirectives) )

        if ( null !== $this->restRequestConfig && isset($this->restRequestConfig->format) ) {

            // A format was specified. Substitute any existing parameters in the format string.

            $substitutions = array();
            $queryString = Utilities::substituteVariables($this->restRequestConfig->format,
                                                          $this->restParameters,
                                                          $substitutions);

            if ( false !== strpos($queryString, '${^REMAINING}') ) {
                $used = array_combine($substitutions, $substitutions);
                $remaining = array_diff_key($this->restParameters, $used);
                $parameters = implode("&", array_map(function ($v, $k) {
                            return $k . "=" . urlencode($v);
                        },
                        $remaining,
                        array_keys($remaining))
                    );
                $queryString = Utilities::substituteVariables($queryString, array('^REMAINING' => $parameters));
            }
        } else {
            // Use standard query string format

            $parameters = array_map(function ($v, $k) {
                    return $k . "=" . urlencode($v);
                },
                $this->restParameters,
                array_keys($this->restParameters)
                );
            $queryString = "?" . implode("&", $parameters);
        }

        // $newUrl = curl_getinfo($this->sourceHandle, CURLINFO_EFFECTIVE_URL) . $queryString;
        $this->currentUrl = $newUrl = $this->sourceEndpoint->getBaseUrl() . $queryString;
        curl_setopt($this->sourceHandle, CURLOPT_URL, $newUrl);

        return $this;

    }  // setRestUrlWithParameters()

    /* ------------------------------------------------------------------------------------------
     * Set up the url for the next record.
     *
     * @param $response The REST response, used to check for a "next" key that will tell us the url
     *   for the next set of results
     * @param $nextKey The name of the "next" key
     *
     * @return true on success, false if there are no more records to fetch
     * ------------------------------------------------------------------------------------------
     */

    private function setNextUrl(\stdClass $response, $nextKey)
    {
        // If the next key was specified, use the value from the response for the next call. If we are
        // using a source query, do not use the next key returned in the response.

        if ( null !== $this->etlSourceQuery && null !== $this->etlSourceQueryResult ) {

            // Continue pulling from the source query until we reach the end or we pass parameter verification

            while ( false !== ($row = $this->etlSourceQueryResult->fetch(PDO::FETCH_ASSOC)) ) {

                foreach( $row as $k => $v ) {
                    $this->setParameter($k, $v);
                }

                // Need to be able to skip this not end the run.

                if ( false !== $this->setRestUrlWithParameters() ) {
                    break;
                }
            }
            if ( false === $row ) {
                return false;
            }

        } else if ( null !== $nextKey ) {
            if ( ! isset($response->$nextKey) || null === $response->$nextKey ) {
                $this->logger->warning("Next property '$nextKey' not present or has null value in response, finished.");
                return false;
            } else {
                $this->currentUrl = $response->$nextKey;
                curl_setopt($this->sourceHandle, CURLOPT_URL, $response->$nextKey);
            }
        } else {
            // No next key and no source query
            return false;
        }

        $this->logger->debug("REST url: {$this->currentUrl}");

        if ( null !== $this->sourceEndpoint->getSleepMicroseconds() ) {
            usleep($this->sourceEndpoint->getSleepMicroseconds());
        }

        return true;

    }  // setNextUrl()

    /* ------------------------------------------------------------------------------------------
     * Verify that transformation and validation directives are properly defined with a type, value,
     * and any necessary formatting directives.  This will allow us to keep the apply() methods
     * cleaner.
     *
     * @return true if the formatting is properly defined
     * @throw Exception if there are malformed or unsupported formatting directives.
     * ------------------------------------------------------------------------------------------
     */

    private function verifyDirectives()
    {
        foreach ( $this->parameterDirectives as $parameter => $directives ) {

            if ( isset($directives->transform) ) {
                $transformList = ( is_array($directives->transform) ? $directives->transform : array($directives->transform) );
                foreach ( $transformList as $directive ) {
                    if ( ! is_object($directive) ) {
                        $msg = "Transformation directives for '$parameter' must be an object";
                        $this->logAndThrowException($msg);
                    }
                    $this->verifyTransformDirective($parameter, $directive);
                }
            }

            if ( isset($directives->verify) ) {
                $verifyList = ( is_array($directives->verify) ? $directives->verify : array($directives->verify) );
                foreach ( $verifyList as $directive ) {
                    if ( ! is_object($directive) ) {
                        $msg = "Verification directives for '$parameter' must be an object";
                        $this->logAndThrowException($msg);
                    }
                    $this->verifyVerifyDirective($parameter, $directive);
                }
            }

        }  // foreach ( $this->parameterDirectives as $key => $directives )

        foreach ( $this->responseDirectives as $key => $directives ) {

            if ( isset($directives->transform) ) {
                $transformList = ( is_array($directives->transform) ? $directives->transform : array($directives->transform) );
                foreach ( $transformList as $directive ) {
                    if ( ! is_object($directive) ) {
                        $msg = "Transformation directives for '$key' must be an object";
                        $this->logAndThrowException($msg);
                    }
                    $this->verifyTransformDirective($key, $directive);
                }
            }

            if ( isset($directives->verify) ) {
                $verifyList = ( is_array($directives->verify) ? $directives->verify : array($directives->verify) );
                foreach ( $verifyList as $directive ) {
                    if ( ! is_object($directive) ) {
                        $msg = "Verification directives for '$key' must be an object";
                        $this->logAndThrowException($msg);
                    }
                    $this->verifyVerifyDirective($key, $directive);
                }
            }

        }  // foreach ( $this->parameterDirectives as $key => $directives )

        return true;

    }  //  verifyDirectives()

    /* ------------------------------------------------------------------------------------------
     * Verify transformation directives for a correctly formatted directive and attempty to verify the
     * format if possible.
     *
     * @paramter $key The directive key (typically a parameter or response key)
     * @parameter $directive A stdClass object containing the directive definition
     *
     * @return true if verification passed
     *
     * @throw Exception if verificaiton failed
     * ------------------------------------------------------------------------------------------
     */

    private function verifyTransformDirective($key, \stdClass $directive)
    {
        if ( ! isset($directive->type) || ! isset($directive->format) ) {
            $msg = "Transform directive for '$key' must specify a type and format.";
            $this->logAndThrowException($msg);
        }

        switch ( $directive->type ) {
        case 'datetime':
            break;
        case 'sprintf':
            break;
        case 'regex':
            if ( false === preg_match($directive->format, "test") ) {
                $msg = "Invalid regex format '{$directive->format}' for key '$key'";
                $this->logAndThrowException($msg);
            }
        default:
            $msg = "Unsupported transform type '{$directive->type}' for key '$key'";
            $this->logAndThrowException($msg);
            break;
        }

        return true;
    }  // verifyTransformDirective()

    /* ------------------------------------------------------------------------------------------
     * Verify verificaiton directives for a correctly formatted directive and attempty to verify the
     * format if possible.
     *
     * @paramter $key The directive key (typically a parameter or response key)
     * @parameter $directive A stdClass object containing the directive definition
     *
     * @return true if verification passed
     *
     * @throw Exception if verificaiton failed
     * ------------------------------------------------------------------------------------------
     */

    private function verifyVerifyDirective($key, \stdClass $directive)
    {
        if ( ! isset($directive->type) || ! isset($directive->format) ) {
            $msg = "Transform directive for '$key' must specify a type and format.";
            $this->logAndThrowException($msg);
        }

        switch ( $directive->type ) {
        case 'regex':
            if ( false === preg_match($directive->format, "test") ) {
                $msg = "Invalid regex format '{$directive->format}' for key '$key'";
                $this->logAndThrowException($msg);
            }
            break;
        default:
            $msg = "Unsupported transform type '{$directive->type}' for key '$key'";
            $this->logAndThrowException($msg);
            break;
        }

        return true;
    }  // verifyTransformDirective()

    /* ------------------------------------------------------------------------------------------
     * Apply directives, in order, to a value. Transformation directives are processed first followed
     * by verification directives. Failed directives will throw an exception.
     *
     * @param $value The value to transform/verify
     * @param $directives A stdClass object containing all of the directives
     *
     * @return The transformed value (unchanged if no transformation directives are present)
     * ------------------------------------------------------------------------------------------
     */

    private function applyDirectives($value, \stdClass $directives)
    {
        // Apply transform directives first, then verification directives

        if ( isset($directives->transform) ) {
            $transformList = ( is_array($directives->transform) ? $directives->transform : array($directives->transform) );
            foreach ( $transformList as $directive ) {
                $value = $this->applyTransformDirective($value, $directive);
            }
        }

        if ( isset($directives->verify) ) {
            $verifyList = ( is_array($directives->verify) ? $directives->verify : array($directives->verify) );
            foreach ( $verifyList as $directive ) {
                $this->applyVerifyDirective($value, $directive);
            }
        }

        return $value;

    }  // applyDirectives()

    /* ------------------------------------------------------------------------------------------
     * Apply individual transformation directives.
     *
     * @param $value The value to transform
     * @param $directive A stdClass defining a single transformation directive
     *
     * @return The transformed value
     *
     * @throw Exception if one of the transformations failed.
     * ------------------------------------------------------------------------------------------
     */

    private function applyTransformDirective($value, \stdClass $directive)
    {
        switch ( $directive->type ) {
        case 'datetime':
            $value = date($directive->format, strtotime($value));
            break;
        case 'regex':
            $matches = null;
            $matched = preg_match($directive->format, $value, $matches);
            if ( false === $matched ) {
                $msg = "Error transforming regex '{$directive->format}'";
                $this->logAndThrowException($msg);
            } else if ( 1 == $matched ) {
                $value = $matches[0];
            }
            break;
        case 'sprintf':
            $value = sprintf($directive->format, $value);
            break;
        default:
            break;
        }  // switch ( $directive->type )

        return $value;

    }  // applyTransformDirective()

    /* ------------------------------------------------------------------------------------------
     * Apply individual verification directives.
     *
     * @param $value The value to verify
     * @param $directive A stdClass defining a single verificaiton directive
     *
     * @return true on success
     *
     * @throw Exception if one of the verifications failed.
     * ------------------------------------------------------------------------------------------
     */

    private function applyVerifyDirective($value, \stdClass $directive)
    {
        switch ( $directive->type ) {
        case 'regex':
            $matched = preg_match($directive->format, $value);
            if ( 0 === $matched ) {
                $msg = "Failed {$directive->type} ({$directive->format}) verification for '$value'";
                $this->logAndThrowException($msg);
            }
            break;
        default:
            break;
        }  // switch ( $directive->type )

        return true;

    }  // verifyTransformDirective()

}  // class RestIngestor
