<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Plugins/SearchEngine/ElasticSearch.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2015 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage Search
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

require_once(__CA_LIB_DIR__.'/core/Configuration.php');
require_once(__CA_LIB_DIR__.'/core/Datamodel.php');
require_once(__CA_LIB_DIR__.'/core/Plugins/WLPlug.php');
require_once(__CA_LIB_DIR__.'/core/Plugins/IWLPlugSearchEngine.php');
require_once(__CA_LIB_DIR__.'/core/Plugins/SearchEngine/BaseSearchPlugin.php');
require_once(__CA_LIB_DIR__.'/core/Plugins/SearchEngine/ElasticSearchResult.php');

require_once(__CA_LIB_DIR__.'/core/Plugins/SearchEngine/ElasticSearch/Field.php');
require_once(__CA_LIB_DIR__.'/core/Plugins/SearchEngine/ElasticSearch/Mapping.php');

class WLPlugSearchEngineElasticSearch extends BaseSearchPlugin implements IWLPlugSearchEngine {
	# -------------------------------------------------------
	protected $opa_doc_content_buffer = array();

	protected $opn_indexing_subject_tablenum = null;
	protected $opn_indexing_subject_row_id = null;
	protected $ops_indexing_subject_tablename = null;

	/**
	 * @var \Elasticsearch\Client
	 */
	protected $opo_client;

	static $s_doc_content_buffer = array();
	static $s_element_code_cache = array();
	# -------------------------------------------------------
	public function __construct($po_db=null) {
		parent::__construct($po_db);

		// allow overriding settings from search.conf via constant (usually defined in bootstrap file)
		// this is useful for multi-instance setups which have the same set of config files for multiple instances
		if(defined('__CA_ELASTICSEARCH_BASE_URL__') && (strlen(__CA_ELASTICSEARCH_BASE_URL__)>0)) {
			$this->ops_elasticsearch_base_url = __CA_ELASTICSEARCH_BASE_URL__;
		} else {
			$this->ops_elasticsearch_base_url = $this->opo_search_config->get('search_elasticsearch_base_url');
		}

		if(defined('__CA_ELASTICSEARCH_INDEX_NAME__') && (strlen(__CA_ELASTICSEARCH_INDEX_NAME__)>0)) {
			$this->ops_elasticsearch_index_name = __CA_ELASTICSEARCH_INDEX_NAME__;
		} else {
			$this->ops_elasticsearch_index_name = $this->opo_search_config->get('search_elasticsearch_index_name');
		}

		if(is_writable(__CA_APP_DIR__.'/log/elasticsearch.log')) {
			$o_logger = Elasticsearch\ClientBuilder::defaultLogger(__CA_APP_DIR__.'/log/elasticsearch.log', Monolog\Logger::DEBUG);
		}

		$this->opo_client = Elasticsearch\ClientBuilder::create()
			->setHosts([$this->ops_elasticsearch_base_url])
			->setRetries(2)
			->setLogger($o_logger)
			->build();

		$this->refreshMapping();
	}
	# -------------------------------------------------------
	/**
	 * Get ElasticSearch index name
	 * @return string
	 */
	protected function getIndexName() {
		return $this->ops_elasticsearch_index_name;
	}
	# -------------------------------------------------------
	/**
	 * Refresh ElasticSearch mapping if necessary
	 * @param bool $pb_force force refresh if set to true [default is false]
	 */
	protected function refreshMapping($pb_force=false) {
		$o_mapping = new ElasticSearch\Mapping();
		if($o_mapping->needsRefresh() || $pb_force) {
			try {
				$this->getClient()->indices()->create(array('index' => $this->getIndexName()));
			} catch (Elasticsearch\Common\Exceptions\BadRequest400Exception $e) {
				// noop -- the exception happens when the index already exists, which is good
			}

			$this->setIndexSettings();

			foreach($o_mapping->get() as $vs_table => $va_config) {
				$this->getClient()->indices()->putMapping(array(
					'index' => $this->getIndexName(),
					'type' => $vs_table,
					'body' => array($vs_table => $va_config)
				));
			}
		}
	}
	# -------------------------------------------------------
	protected function setIndexSettings() {
		$this->getClient()->indices()->close(array(
			'index' => $this->getIndexName()
		));

		$this->getClient()->indices()->putSettings(array(
				'index' => $this->getIndexName(),
				'body' => array(
					'analysis' => array(
						'analyzer' => array(
							'analyzer_keyword' => array(
								'tokenizer' => 'keyword',
								'filter' => 'lowercase'
							)
						)
					)
				)
			)
		);

		$this->getClient()->indices()->open(array(
			'index' => $this->getIndexName()
		));
	}
	# -------------------------------------------------------
	/**
	 *
	 *
	 * @param int $pn_subject_tablenum
	 * @param array $pa_subject_row_ids
	 * @param int $pn_content_tablenum
	 * @param string $ps_content_fieldnum
	 * @param int $pn_content_row_id
	 * @param string $ps_content
	 * @param array $pa_options
	 *		literalContent = array of text content to be applied without tokenization
	 *		BOOST = Indexing boost to apply
	 *		PRIVATE = Set indexing to private
	 */
	/*public function updateIndexingInPlace($pn_subject_tablenum, $pa_subject_row_ids, $pn_content_tablenum, $ps_content_fieldnum, $pn_content_row_id, $ps_content, $pa_options=null) {
	}*/
	# -------------------------------------------------------
	/**
	 * Get ElasticSearch client
	 * @return \Elasticsearch\Client
	 */
	protected function getClient() {
		return $this->opo_client;
	}
	# -------------------------------------------------------
	public function init() {
		if(($vn_max_indexing_buffer_size = (int)$this->opo_search_config->get('max_indexing_buffer_size')) < 1) {
			$vn_max_indexing_buffer_size = 1000;
		}

		$this->opa_options = array(
			'start' => 0,
			'limit' => 100000,												// maximum number of hits to return [default=100000],
			'maxIndexingBufferSize' => $vn_max_indexing_buffer_size			// maximum number of indexed content items to accumulate before writing to the index
		);

		$this->opa_capabilities = array(
			'incremental_reindexing' => false // @todo implement updateIndexingInPlace()
		);
	}
	# -------------------------------------------------------
	/**
	 * Completely clear index (usually in preparation for a full reindex)
	 *
	 * @param null|int $pn_table_num
	 * @return bool
	 */
	public function truncateIndex($pn_table_num = null) {
		if(!$pn_table_num) {
			// nuke the entire index
			try {
				$this->getClient()->indices()->delete(['index' => $this->getIndexName()]);
			} catch(Elasticsearch\Common\Exceptions\Missing404Exception $e) {
				// noop
			} finally {
				$this->getClient()->indices()->create(['index' => $this->getIndexName()]);
				$this->refreshMapping(true);
			}
		} else {
			// use scoll API to find all documents in a particular mapping/table and delete them using bulk API
			// @see https://www.elastic.co/guide/en/elasticsearch/reference/current/search-request-scroll.html
			// @see https://www.elastic.co/guide/en/elasticsearch/client/php-api/2.0/_search_operations.html#_scan_scroll

			if(!$vs_table_name = $this->opo_datamodel->getTableName($pn_table_num)) { return false; }

			$va_search_params = array(
				'search_type' => 'scan',    // use search_type=scan
				'scroll' => '1m',          // how long between scroll requests. should be small!
				'index' => $this->getIndexName(),
				'type' => $vs_table_name,
				'body' => array(
					'query' => array(
						'match_all' => array()
					)
				)
			);

			$va_tmp = $this->getClient()->search($va_search_params);   // Execute the search
			$vs_scroll_id = $va_tmp['_scroll_id'];   // The response will contain no results, just a _scroll_id

			// Now we loop until the scroll "cursors" are exhausted
			$va_delete_params = array();
			while (\true) {

				$va_response = $this->getClient()->scroll([
						'scroll_id' => $vs_scroll_id,  //...using our previously obtained _scroll_id
						'scroll' => '1m'           // and the same timeout window
					]
				);

				if (sizeof($va_response['hits']['hits']) > 0) {
					foreach($va_response['hits']['hits'] as $va_result) {
						$va_delete_params['body'][] = array(
							'delete' => array(
								'_index' => $this->getIndexName(),
								'_type' => $vs_table_name,
								'_id' => $va_result['_id']
							)
						);
					}

					// Must always refresh your _scroll_id!  It can change sometimes
					$vs_scroll_id = $va_response['_scroll_id'];
				} else {
					// No results, scroll cursor is empty
					break;
				}
			}

			if(sizeof($va_delete_params) > 0) {
				$this->getClient()->bulk($va_delete_params);
			}
		}
		return true;
	}

	# -------------------------------------------------------
	public function setTableNum($pn_table_num) {
		$this->opn_indexing_subject_tablenum = $pn_table_num;
	}
	# -------------------------------------------------------
	public function __destruct() {
		if (is_array(WLPlugSearchEngineElasticSearch::$s_doc_content_buffer) && sizeof(WLPlugSearchEngineElasticSearch::$s_doc_content_buffer)) {
			$this->flushContentBuffer();
		}
	}
	# -------------------------------------------------------
	/**
	 * Do search
	 *
	 * @param int $pn_subject_tablenum
	 * @param string $ps_search_expression
	 * @param array $pa_filters
	 * @param null|Zend_Search_Lucene_Search_Query $po_rewritten_query
	 * @return WLPlugSearchEngineElasticSearchResult
	 */
	public function search($pn_subject_tablenum, $ps_search_expression, $pa_filters=array(), $po_rewritten_query=null) {
		Debug::msg("[ElasticSearch] incoming search query is: {$ps_search_expression}");
		$o_query = new ElasticSearch\Query($pn_subject_tablenum, $ps_search_expression, $po_rewritten_query, $pa_filters);

		$va_search_params = [
			'index' => $this->getIndexName(),
			'type' => $this->opo_datamodel->getTableName($pn_subject_tablenum),
			'body' => [
				'query' => [
					'match_all' => []
				]
			]
		];

		$va_results = $this->getClient()->search($va_search_params);
		return new WLPlugSearchEngineElasticSearchResult($va_results['hits']['hits'], $pn_subject_tablenum);
	}
	# -------------------------------------------------------
	/**
	 * Start row indexing
	 * @param int $pn_subject_tablenum
	 * @param int $pn_subject_row_id
	 */
	public function startRowIndexing($pn_subject_tablenum, $pn_subject_row_id){
		$this->opa_doc_content_buffer = array();
		$this->opn_indexing_subject_tablenum = $pn_subject_tablenum;
		$this->opn_indexing_subject_row_id = $pn_subject_row_id;
		$this->ops_indexing_subject_tablename = $this->opo_datamodel->getTableName($pn_subject_tablenum);
	}
	# -------------------------------------------------------
	/**
	 * Index field
	 * @param int $pn_content_tablenum
	 * @param string $ps_content_fieldname
	 * @param int $pn_content_row_id
	 * @param mixed $pm_content
	 * @param array $pa_options
	 * @return null
	 */
	public function indexField($pn_content_tablenum, $ps_content_fieldname, $pn_content_row_id, $pm_content, $pa_options) {
		$o_field = new ElasticSearch\Field($pn_content_tablenum, $ps_content_fieldname);

		foreach($o_field->getIndexingFragment($pm_content, $pa_options) as $vs_key => $vm_val) {
			$this->opa_doc_content_buffer[$vs_key][] = $vm_val;
		}
	}
	# -------------------------------------------------------
	/**
	 * Commit indexing for row
	 * That doesn't necessarily mean it's actually written to the index.
	 * We still keep the data local until the document buffer is full.
	 */
	public function commitRowIndexing() {
		if(sizeof($this->opa_doc_content_buffer) > 0) {
			WLPlugSearchEngineElasticSearch::$s_doc_content_buffer[
				$this->ops_indexing_subject_tablename.'/'.
				$this->opn_indexing_subject_row_id
			] = $this->opa_doc_content_buffer;
		}

		unset($this->opn_indexing_subject_tablenum);
		unset($this->opn_indexing_subject_row_id);
		unset($this->ops_indexing_subject_tablename);

		if (sizeof(WLPlugSearchEngineElasticSearch::$s_doc_content_buffer) > $this->getOption('maxIndexingBufferSize')) {
			$this->flushContentBuffer();
		}
	}
	# -------------------------------------------------------
	/**
	 * Delete indexing for row
	 * @param int $pn_subject_tablenum
	 * @param int $pn_subject_row_id
	 */
	public function removeRowIndexing($pn_subject_tablenum, $pn_subject_row_id) {
		try {
			$this->getClient()->delete($va_params = array(
				'index' => $this->getIndexName(),
				'type' => $this->opo_datamodel->getTableName($pn_subject_tablenum),
				'id' => $pn_subject_row_id
			));
		} catch (Elasticsearch\Common\Exceptions\Missing404Exception $e) {
			// noop
		}
	}
	# ------------------------------------------------
	/**
	 * Flush content buffer and write to index
	 * @throws Elasticsearch\Common\Exceptions\NoNodesAvailableException
	 */
	public function flushContentBuffer() {
		$va_bulk_params = array();

		foreach(WLPlugSearchEngineElasticSearch::$s_doc_content_buffer as $vs_key => $va_doc_content_buffer) {
			$va_tmp = explode('/', $vs_key);
			$vs_table_name = $va_tmp[0];
			$vn_primary_key = intval($va_tmp[1]);

			$va_bulk_params['body'][] = array(
				'index' => array(
					'_index' => $this->getIndexName(),
					'_type' => $vs_table_name,
					'_id' => $vn_primary_key
				)
			);

			$va_bulk_params['body'][] = $va_doc_content_buffer;
		}

		// @see https://www.elastic.co/guide/en/elasticsearch/reference/current/docs-bulk.html
		// @see https://www.elastic.co/guide/en/elasticsearch/client/php-api/2.0/_indexing_documents.html#_bulk_indexing
		$this->getClient()->bulk($va_bulk_params);

		$this->opa_doc_content_buffer = array();
		WLPlugSearchEngineElasticSearch::$s_doc_content_buffer = array();
	}
	# -------------------------------------------------------
	public function optimizeIndex($pn_tablenum) {
		// noop
	}
	# --------------------------------------------------
	public function engineName() {
		return 'ElasticSearch';
	}
	# --------------------------------------------------
	/**
	 * Performs the quickest possible search on the index for the specfied table_num in $pn_table_num
	 * using the text in $ps_search. Unlike the search() method, quickSearch doesn't support
	 * any sort of search syntax. You give it some text and you get a collection of (hopefully) relevant results back quickly.
	 * quickSearch() is intended for autocompleting search suggestion UI's and the like, where performance is critical
	 * and the ability to control search parameters is not required.
	 *
	 * @param $pn_table_num - The table index to search on
	 * @param $ps_search - The text to search on
	 * @param $pa_options - an optional associative array specifying search options. Supported options are: 'limit' (the maximum number of results to return)
	 *
	 * @return Array - an array of results is returned keyed by primary key id. The array values boolean true. This is done to ensure no duplicate row_ids
	 *
	 */
	public function quickSearch($pn_table_num, $ps_search, $pa_options=array()) {
		if (!is_array($pa_options)) { $pa_options = array(); }
		$vn_limit = caGetOption('limit', $pa_options, 0);

		$o_result = $this->search($pn_table_num, $ps_search);
		$va_pks = $o_result->getPrimaryKeyValues();
		if($vn_limit) {
			$va_pks = array_slice($va_pks, 0, $vn_limit);
		}

		return array_flip($va_pks);
	}
}
