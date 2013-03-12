<?php
//-----------------------------------------------------------------------------
/**
 *
 * This file is part of Open Library System.
 * Copyright � 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/

//-----------------------------------------------------------------------------
require_once('OLS_class_lib/webServiceServer_class.php');
require_once 'OLS_class_lib/memcache_class.php';
require_once 'OLS_class_lib/solr_query_class.php';

//-----------------------------------------------------------------------------
define(REL_TO_INTERNAL_OBJ, 1);       // relation points to internal object
define(REL_TO_EXTERNAL_OBJ, 2);     // relation points to external object

//-----------------------------------------------------------------------------
class openSearch extends webServiceServer {
  protected $cql2solr;
  protected $curl;
  protected $cache;
  protected $search_profile;
  protected $search_profile_version;
  protected $repository; // array containing solr and fedora uri's
  protected $tracking_id; 
  protected $number_of_fedora_calls = 0;
  protected $number_of_fedora_cached = 0;
  protected $separate_field_query_style = TRUE; // seach as field:(a OR b) ie FALSE or (field:a OR field:b) ie TRUE


  public function __construct() {
    webServiceServer::__construct('opensearch.ini');

    if (!$timeout = $this->config->get_value('curl_timeout', 'setup'))
      $timeout = 20;
    $this->curl = new curl();
    $this->curl->set_option(CURLOPT_TIMEOUT, $timeout);

    define(DEBUG_ON, $this->debug);
    if (!$mir = $this->config->get_value('max_identical_relation_names', 'setup'))
      $mir = 20;
    define(MAX_IDENTICAL_RELATIONS, $mir);
    define(MAX_OBJECTS_IN_WORK, 100);
  }

  /**
      \brief Handles the request and set up the response
  */

  public function search($param) {
    // set some defines
    $this->tracking_id = verbose::set_tracking_id('os', $param->trackingId->_value);
    if (!$this->aaa->has_right('opensearch', 500)) {
      $ret_error->searchResponse->_value->error->_value = 'authentication_error';
      return $ret_error;
    }
    define('WSDL', $this->config->get_value('wsdl', 'setup'));
    define('MAX_COLLECTIONS', $this->config->get_value('max_collections', 'setup'));

    // check for unsupported stuff
    $ret_error->searchResponse->_value->error->_value = &$unsupported;
    if ($param->format->_value == 'short') {
      $unsupported = 'Error: format short is not supported';
    }
    if ($param->format->_value == 'full') {
      $unsupported = 'Error: format full is not supported';
    }
    if (empty($param->query->_value)) {
      $unsupported = 'Error: No query found in request';
    }
    $repositories = $this->config->get_value('repository', 'setup');
    if (empty($param->repository->_value)) {
      $repository_name = $this->config->get_value('default_repository', 'setup');
      $this->repository = $repositories[$repository_name];
    }
    else {
      $repository_name = $param->repository->_value;
      if (!$this->repository = $repositories[$param->repository->_value]) {
        $unsupported = 'Error: Unknown repository: ' . $param->repository->_value;
      }
    }

// for testing and group all
    if (count($this->aaa->aaa_ip_groups) == 1 && $this->aaa->aaa_ip_groups['all']) {
      $param->agency->_value = '100200';
      $param->profile->_value = 'test';
    }
    $this->search_profile_version = $this->repository['search_profile_version'];
    if (empty($param->agency->_value) && empty($param->profile->_value)) {
      $param->agency->_value = $this->config->get_value('agency_fallback', 'setup');
      $param->profile->_value = $this->config->get_value('profile_fallback', 'setup');
    }
    if (empty($param->agency->_value)) {
      $unsupported = 'Error: No agency in request';
    }
    elseif (empty($param->profile->_value)) {
      $unsupported = 'Error: No profile in request';
    }
    elseif (!($this->search_profile = $this->fetch_profile_from_agency($param->agency->_value, $param->profile->_value, $this->search_profile_version))) {
      $unsupported = 'Error: Cannot fetch profile: ' . $param->profile->_value .
                     ' for ' . $param->agency->_value;
    }
    if ($unsupported) return $ret_error;
    $filter_agency = $this->set_solr_filter($this->search_profile, $this->search_profile_version);

    $use_work_collection = ($param->collectionType->_value <> 'manifestation');
    if (($rr = $param->userDefinedRanking) || ($rr = $param->userDefinedBoost->_value->userDefinedRanking)) {
      $rank = 'rank';
      $rank_user['tie'] = $rr->_value->tieValue->_value;

      if (is_array($rr->_value->rankField)) {
        foreach ($rr->_value->rankField as $rf) {
          $boost_type = ($rf->_value->fieldType->_value == 'word' ? 'word_boost' : 'phrase_boost');
          $rank_user[$boost_type][$rf->_value->fieldName->_value] = $rf->_value->weight->_value;
          $rank .= '_' . $boost_type . '-' . $rf->_value->fieldName->_value . '-' . $rf->_value->weight->_value;
        }
      }
      else {
        $boost_type = ($rr->_value->rankField->_value->fieldType->_value == 'word' ? 'word_boost' : 'phrase_boost');
        $rank_user[$boost_type][$rr->_value->rankField->_value->fieldName->_value] = $rr->_value->rankField->_value->weight->_value;
        $rank .= '_' . $boost_type . '-' . $rr->_value->rankField->_value->fieldName->_value . '-' . $rr->_value->rankField->_value->weight->_value;
      }
      // make sure anyIndexes will be part of the dismax-search
      if (empty($rank_user['word_boost']['cql.anyIndexes'])) $rank_user['word_boost']['cql.anyIndexes'] = 1;
      if (empty($rank_user['phrase_boost']['cql.anyIndexes'])) $rank_user['phrase_boost']['cql.anyIndexes'] = 1;
      $rank_type[$rank] = $rank_user;
    }
    elseif ($sort = $param->sort->_value) {
      $sort_type = $this->config->get_value('sort', 'setup');
      if (!isset($sort_type[$sort])) $unsupported = 'Error: Unknown sort: ' . $sort;
    }
    elseif (($rank = $param->rank->_value) || ($rank = $param->userDefinedBoost->_value->rank->_value)) {
      $rank_type = $this->config->get_value('rank', 'setup');
      if (!isset($rank_type[$rank])) $unsupported = 'Error: Unknown rank: ' . $rank;
    }

    if (($boost_str = $this->boostUrl($param->userDefinedBoost->_value->boostField)) && empty($rank)) {
      $rank_type = $this->config->get_value('rank', 'setup');
      $rank = 'rank_none';
    }

    $format = $this->set_format($param->objectFormat, $this->config->get_value('open_format', 'setup'), $this->config->get_value('solr_format', 'setup'));
    
    if ($unsupported) return $ret_error;

    /**
    *  Approach
    *  a) Do the solr search and fetch enough unit-ids in result
    *  b) Fetch a unit-ids work-object unless the record has been found
    *     in an earlier handled work-objects
    *  c) Collect unit-ids in this work-object
    *  d) repeat b. and c. until the request number of objects is found
    *  e) if allObject is not set, do a new search combined the users search
    *     with an or'ed list of the unit-ids in the active objects and
    *     remove the unit-ids not found in the result
    *  f) Read full records fom fedora for objects in result
    *
    *  if $use_work_collection is FALSE skip b) to e)
    */

    $ret_error->searchResponse->_value->error->_value = &$error;

    $this->watch->start('Solr');
    $start = $param->start->_value;
    if (empty($start) && $step_value) {
      $start = 1;
    }
    $step_value = min($param->stepValue->_value, MAX_COLLECTIONS);
    $use_work_collection |= $sort_type[$sort] == 'random';
    $key_work_struct = md5($param->query->_value . $repository_name . $filter_agency .
                              $use_work_collection .  $sort . $rank . $boost_str . $this->version);

    if ($param->queryLanguage->_value == 'cqldan') {
      define('AND_OP', 'og');
      define('OR_OP', 'eller');
    }
    else {
      define('AND_OP', 'AND');
      define('OR_OP', 'OR');
      $param->queryLanguage->_value = 'cqleng';
    }
    $this->cql2solr = new SolrQuery('opensearch_cql.xml', $this->config, $param->queryLanguage->_value);
    $solr_query = $this->cql2solr->cql_2_edismax($param->query->_value);
    if ($solr_query['error']) {
      $error = $solr_query['error'];
      return $ret_error;
    }
    if (!$solr_query['operands']) {
      $error = 'Error: No query found in request';
      return $ret_error;
    }
    if ($sort) {
      $sort_q = '&sort=' . urlencode($sort_type[$sort]);
    }
    if ($rank_type[$rank]) {
      $rank_qf = $this->cql2solr->make_boost($rank_type[$rank]['word_boost']);
      $rank_pf = $this->cql2solr->make_boost($rank_type[$rank]['phrase_boost']);
      $rank_tie = $rank_type[$rank]['tie'];
      $rank_q = '&qf=' . urlencode($rank_qf) .  '&pf=' . urlencode($rank_pf) .  '&tie=' . $rank_tie;
    }

    if ($filter_agency) {
      $filter_q = rawurlencode($filter_agency);
    }

    $rows = ($start + $step_value + 100) * 2;
    if ($param->facets->_value->facetName) {
      $facet_q .= '&facet=true&facet.limit=' . $param->facets->_value->numberOfTerms->_value;
      if (is_array($param->facets->_value->facetName)) {
        foreach ($param->facets->_value->facetName as $facet_name) {
          $facet_q .= '&facet.field=' . $facet_name->_value;
        }
      }
      else
        $facet_q .= '&facet.field=' . $param->facets->_value->facetName->_value;
    }

    verbose::log(TRACE, 'CQL to EDISMAX: ' . $param->query->_value . ' -> ' . $solr_query['edismax']);

    $debug_query = $this->xs_boolean($param->queryDebug->_value);

    // do the query
    if ($sort == 'random') {
      if ($err = $this->get_solr_array($solr_query['edismax'], 0, 0, '', '', $facet_q, $filter_q, '', $debug_query, $solr_arr))
        $error = $err;
    }
    else {
      if ($err = $this->get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, $facet_q, $filter_q, $boost_str, $debug_query, $solr_arr))
        $error = $err;
      else {
        $this->extract_unit_id_from_solr($solr_arr['response']['docs'], $search_ids);
        $numFound = $solr_arr['response']['numFound'];
      }
    }
    $this->watch->stop('Solr');

    if ($error) return $ret_error;

    if ($debug_query) {
      $debug_result->rawQueryString->_value = $solr_arr['debug']['rawquerystring'];
      $debug_result->queryString->_value = $solr_arr['debug']['querystring'];
      $debug_result->parsedQuery->_value = $solr_arr['debug']['parsedquery'];
      $debug_result->parsedQueryString->_value = $solr_arr['debug']['parsedquery_toString'];
    }
    $facets = $this->parse_for_facets($solr_arr['facet_counts']);

    $this->watch->start('Build_id');
    $work_ids = $used_search_fids = array();
    if ($sort == 'random') {
      $rows = min($step_value, $numFound);
      $more = $step_value < $numFound;
      for ($w_idx = 0; $w_idx < $rows; $w_idx++) {
        do {
          $no = rand(0, $numFound-1);
        }
        while (isset($used_search_fid[$no]));
        $used_search_fid[$no] = TRUE;
        $this->get_solr_array($solr_query['edismax'], $no, 1, '', '', '', $filter_q, '', $debug_query, $solr_arr);
        $uid = $solr_arr['response']['docs'][0]['unit.id'];
        //$local_data[$uid] = $solr_arr['response']['docs']['rec.collectionIdentifier'];
        $work_ids[] = array($uid);
      }
    }
    else {
      $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                               $this->config->get_value('cache_port', 'setup'),
                               $this->config->get_value('cache_expire', 'setup'));
      if (empty($_GET['skipCache'])) {
        if ($work_struct = $this->cache->get($key_work_struct)) {
          verbose::log(STAT, 'Cache hit, lines: ' . count($work_struct));
        }
        else {
          verbose::log(STAT, 'Cache miss');
        }
      }

      $w_no = 0;

      if (DEBUG_ON) print_r($search_ids);
      //if (DEBUG_ON) print_r($local_data);

      for ($s_idx = 0; isset($search_ids[$s_idx]); $s_idx++) {
        $uid = &$search_ids[$s_idx];
        if (!isset($search_ids[$s_idx+1]) && count($search_ids) < $numFound) {
          $this->watch->start('Solr_add');
          verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . $solr_query['edismax']);
          $rows *= 2;
          if ($err = $this->get_solr_array($solr_query['edismax'], 0, $rows, $sort_q, $rank_q, '', $filter_q, $boost_str, $debug_query, $solr_arr)) {
            $error = $err;
            return $ret_error;
          }
          else {
            $this->extract_unit_id_from_solr($solr_arr['response']['docs'], $search_ids);
            $numFound = $solr_arr['response']['numFound'];
          }
          $this->watch->stop('Solr_add');
        }
        if (FALSE) {
          $this->get_fedora_rels_hierarchy($uid, $unit_result);
          $unit_id = $this->parse_rels_for_unit_id($unit_result);
          if (DEBUG_ON) echo 'UR: ' . $uid . ' -> ' . $unit_id . "\n";
          $uid = $unit_id;
        }
        if ($used_search_fids[$uid]) continue;
        if (count($work_ids) >= $step_value) {
          $more = TRUE;
          break;
        }

        $w_no++;
        // find relations for the record in fedora
        // uid: id as found in solr's fedoraPid
        if ($work_struct[$w_no]) {
          $uid_array = $work_struct[$w_no];
        }
        else {
          if ($use_work_collection) {
            $this->watch->start('get_w_id');
            $this->get_fedora_rels_hierarchy($uid, $record_rels_hierarchy);
            /* ignore the fact that there is no RELS_HIERARCHY datastream
            */
            $this->watch->stop('get_w_id');
            if (DEBUG_ON) echo 'RR: ' . $record_rels_hierarchy . "\n";

            if ($work_id = $this->parse_rels_for_work_id($record_rels_hierarchy)) {
              // find other recs sharing the work-relation
              $this->watch->start('get_fids');
              $this->get_fedora_rels_hierarchy($work_id, $work_rels_hierarchy);
              if (DEBUG_ON) echo 'WR: ' . $work_rels_hierarchy . "\n";
              $this->watch->stop('get_fids');
              if (!$uid_array = $this->parse_work_for_object_ids($work_rels_hierarchy, $uid)) {
                verbose::log(FATAL, 'Fedora fetch/parse work-record: ' . $work_id . ' refered from: ' . $uid);
                $uid_array = array($uid);
              }
              if (count($uid_array) >= MAX_OBJECTS_IN_WORK) {
                verbose::log(FATAL, 'Fedora work-record: ' . $work_id . ' refered from: ' . $uid . ' contains ' . count($uid_array) . ' objects');
                array_splice($uid_array, MAX_OBJECTS_IN_WORK);
              }
              if (DEBUG_ON) {
                echo 'fid: ' . $uid . ' -> ' . $work_id . " -> object(s):\n";
                print_r($uid_array);
              }
            }
            else
              $uid_array = array($uid);
          }
          else
            $uid_array = array($uid);
          $work_struct[$w_no] = $uid_array;
        }

        foreach ($uid_array as $id) {
          $used_search_fids[$id] = TRUE;
          if ($w_no >= $start)
            $work_ids[$w_no][] = $id;
        }
        if ($w_no >= $start)
          $work_ids[$w_no] = $uid_array;
      }
    }

    if (count($work_ids) < $step_value && count($search_ids) < $numFound) {
      verbose::log(FATAL, 'To few search_ids fetched from solr. Query: ' . $solr_query['edismax']);
    }

    // check if the search result contains the ids
    // allObject=0 - remove objects not included in the search result
    // allObject=1 & agency - remove objects not included in agency
    //
    // split into multiple solr-searches each containing slightly less than 1000 elements
    define('MAX_QUERY_ELEMENTS', 950);
    $block_idx = $no_bool = 0;
    if (DEBUG_ON) echo 'work_ids: ' . print_r($work_ids, TRUE) . "\n";
    if ($use_work_collection && $step_value) {
      $no_of_rows = 1;
      $add_query[$block_idx] = '';
      $which_rec_id = 'unit.id';
      foreach ($work_ids as $w_no => $w) {
        if (count($w) > 1 || $format['found_solr_format']) {
          if ($add_query[$block_idx] && ($no_bool + count($w)) > MAX_QUERY_ELEMENTS) {
            $block_idx++;
            $no_bool = 0;
          }
          foreach ($w as $id) {
            $id = str_replace(':', '\:', $id);
            if ($this->separate_field_query_style) {
              $add_query[$block_idx] .= (empty($add_query[$block_idx]) ? '' : ' ' . OR_OP . ' ') . $which_rec_id . ':' . $id;
            }
            else {
              $add_query[$block_idx] .= (empty($add_query[$block_idx]) ? '' : ' ' . OR_OP . ' ') . $id;
            }
            $no_bool++;
            $no_of_rows++;
          }
        }
      }
      if (!empty($add_query[0]) || count($add_query) > 1 || $format['found_solr_format']) {    // use post here because query can be very long
        foreach ($add_query as $add_idx => $add_q) {
          if ($this->separate_field_query_style) {
              $add_q =  '(' . $add_q . ')';
          }
          else {
              $add_q =  $which_rec_id . ':(' . $add_q . ')';
          }
          if ($this->xs_boolean($param->allObjects->_value)) {
            $chk_query['edismax'] .=  $add_q;
          }
          else {
            $chk_query = $this->cql2solr->cql_2_edismax($param->query->_value);
            $chk_query['edismax'] .=  ' ' . AND_OP . ' ' . $add_q;
          }
          if ($chk_query['error']) {
            $error = $chk_query['error'];
            return $ret_error;
          }
          $q = $chk_query['edismax'];
          if ($format['found_solr_format']) {
            foreach ($format as $f) {
              if ($f[is_solr_format]) {
                $add_fl .= ',' . $f['format_name'];
              }
            }
          }
          $post_query = 'q=' . urlencode($q . ' AND unit.isPrimaryObject:true') .
                       '&fq=' . $filter_q .
                       '&wt=phps' .
                       '&start=0' .
                       '&rows=' . '999999' . // $no_of_rows . 
                       '&defType=edismax' .
                       '&fl=unit.isPrimaryObject,unit.id,sort.complexKey' . $add_fl;
          if ($rank_qf) $post_query .= '&qf=' . $rank_qf;
          if ($rank_pf) $post_query .= '&pf=' . $rank_pf;
          if ($rank_tie) $post_query .= '&tie=' . $rank_tie;
          verbose::log(DEBUG, 'Re-search: ' . $this->repository['solr'] . '?' . str_replace('&wt=phps', '', $post_query) . '&debugQuery=on');

          if (DEBUG_ON) {
            echo 'post_array: ' . $this->repository['solr'] . '?' . $post_query . "\n";
          }

          $this->curl->set_post($post_query);
          $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded; charset=utf-8'));
          $this->watch->start('Solr 2');
          $solr_result = $this->curl->get($this->repository['solr']);
          $this->watch->stop('Solr 2');
// remember to clear POST 
          $this->curl->set_option(CURLOPT_POST, 0);
          if (!($solr_2_arr[$add_idx] = unserialize($solr_result))) {
            verbose::log(FATAL, 'Internal problem: Cannot decode Solr re-search');
            $error = 'Internal problem: Cannot decode Solr re-search';
//die();
            return $ret_error;
          }
        }
        foreach ($work_ids as $w_no => $w_list) {
          if (count($w_list) > 1) {
            $hit_fid_array = array();
            foreach ($w_list as $w) {
              foreach ($solr_2_arr as $s_2_a) {
                foreach ($s_2_a['response']['docs'] as $fdoc) {
                  $p_id = $fdoc['unit.id'][0];
                  if ($p_id == $w) {
                    $hit_fid_array[] = $w;
                    $unit_sort_keys[$w] = $fdoc['sort.complexKey'] . '  ' . $p_id;
                    break 2;
                  }
                }
              }
            }
            if (empty($hit_fid_array)) {
              verbose::log(ERROR, 'Re-search: Cannot find any of ' . implode(',', $w_list) . ' in unit.id');
              $work_ids[$w_no] = array($w_list[0]);
            }
            else {
              $work_ids[$w_no] = $hit_fid_array;
            }
          }
        }
      }
      if (DEBUG_ON) echo 'work_ids after research: ' . print_r($work_ids, TRUE) . "\n";
    }

    if (DEBUG_ON) echo 'txt: ' . $txt . "\n";
    if (DEBUG_ON) echo 'solr_2_arr: ' . print_r($solr_2_arr, TRUE) . "\n";
    if (DEBUG_ON) echo 'add_query: ' . print_r($add_query, TRUE) . "\n";
    if (DEBUG_ON) echo 'used_search_fids: ' . print_r($used_search_fids, TRUE) . "\n";

    $this->watch->stop('Build_id');

    if ($this->cache)
      $this->cache->set($key_work_struct, $work_struct);

    $missing_record = $this->config->get_value('missing_record', 'setup');

    // work_ids now contains the work-records and the fedoraPids they consist of
    // now fetch the records for each work/collection
    $this->watch->start('get_recs');
    $collections = array();
    $rec_no = max(1, $start);
    foreach ($work_ids as &$work) {
      $objects = array();
      foreach ($work as $unit_id) {
        $this->get_fedora_rels_addi($unit_id, $fedora_addi_relation);
        $this->get_fedora_rels_hierarchy($unit_id, $unit_rels_hierarchy);
        list($fpid, $unit_members) = $this->parse_unit_for_object_ids($unit_rels_hierarchy);
        if ($this->xs_boolean($param->includeHoldingsCount->_value)) {
          $no_of_holdings = $this->get_holdings($fpid);
        }
        $fpid_sort_keys[$fpid] = str_replace(' holdings ', sprintf(' %04d ', 9999 - intval($no_of_holdings['have'])), $unit_sort_keys[$unit_id]);
        if ($error = $this->get_fedora_raw($fpid, $fedora_result)) {
// fetch empty record from ini-file and use instead of error
          if ($missing_record) {
            $error = NULL;
            $fedora_result = sprintf($missing_record, $fpid);
          }
          else {
            return $ret_error;
          }
        }
        if ($debug_query) {
          unset($explain);
          foreach ($solr_arr['response']['docs'] as $solr_idx => $solr_rec) {
            if ($fpid == $solr_rec['fedoraPid']) {
              //$strange_idx = $solr_idx ? ' '.$solr_idx : '';
              $explain = $solr_arr['debug']['explain'][$fpid];
              break;
            }
          }

        }
        $sort_key = $fpid_sort_keys[$fpid] . ' ' . sprintf('%04d', count($objects));
        $sorted_work[$sort_key] = $unit_id;
        $objects[$sort_key]->_value =
          $this->parse_fedora_object($fedora_result,
                                     $fedora_addi_relation,
                                     $param->relationData->_value,
                                     $fpid,
                                     NULL, // no $filter_agency on search - bad performance
                                     $format,
                                     $no_of_holdings,
                                     $explain);
      }
      $work = $sorted_work;
      unset($sorted_work);
      $o->collection->_value->resultPosition->_value = $rec_no++;
      $o->collection->_value->numberOfObjects->_value = count($objects);
      if (count($objects) > 1) {
        ksort($objects);
      }
      $o->collection->_value->object = $objects;
      $collections[]->_value = $o;
      unset($o);
    }
    $this->watch->stop('get_recs');

  // TODO: if an openFormat is specified, we need to remove data so openFormat dont format unneeded stuff
  // But apparently, openFormat breaks when receiving an empty object
    if ($param->collectionType->_value == 'work-1') {
      foreach ($collections as &$c) {
        $keep_rec = TRUE;
        foreach ($c->_value->collection->_value->object as &$o) {
          if ($keep_rec) {
            foreach ($o->_value as $tag => $val) {
              if (!in_array($tag, array('identifier', 'creationDate', 'formatsAvailable'))) {
                unset($o->_value->$tag);
              }
            }
            $keep_rec = FALSE;
          }
        }
      }
    }

    if ($step_value) {
      if ($format['found_open_format']) {
        $this->format_records($collections, $format);
      }
      if ($format['found_solr_format']) {
        $this->format_solr($collections, $format, $solr_2_arr, $work_ids, $fpid_sort_keys);
      }
      $this->remove_unselected_formats($collections, $format);
    }

// try to get a better hitCount by looking for primaryObjects only 
    if (($start > 1) || $more) {
// ignore errors here
      $err = $this->get_solr_array($solr_query['edismax'] . ' AND unit.isPrimaryObject:true', 0, 0, '', '', '', $filter_q, '', $debug_query, $solr_arr);
      if ($solr_arr['response']['numFound'] > 0) {
        verbose::log(STAT, 'Modify hitcount from: ' . $numFound . ' to ' . $solr_arr['response']['numFound']);
        $numFound = $solr_arr['response']['numFound'];
      }
    }

//var_dump($solr_2_arr);
//var_dump($work_struct);
//die();
    if ($_REQUEST['work'] == 'debug') {
      echo "returned_work_ids: \n";
      print_r($work_ids);
      echo "cache: \n";
      print_r($work_struct);
      die();
    }
    //if (DEBUG_ON) { print_r($work_struct); die(); }
    //if (DEBUG_ON) { print_r($collections); die(); }
    //if (DEBUG_ON) { print_r($solr_arr); die(); }

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = $numFound;
    $result->collectionCount->_value = count($collections);
    $result->more->_value = ($more ? 'true' : 'false');
    $result->searchResult = $collections;
    $result->facetResult->_value = $facets;
    $result->queryDebugResult->_value = $debug_result;
    $result->statInfo->_value->fedoraRecordsCached->_value = $this->number_of_fedora_cached;
    $result->statInfo->_value->fedoraRecordsRead->_value = $this->number_of_fedora_calls;
    $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
    $result->statInfo->_value->trackingId->_value = $this->tracking_id;


    //print_r($collections[0]);
    //exit;

    return $ret;
  }


  /** \brief Get an object in a specific format
  *
  * param: agency: 
  *        profile:
  *        identifier - fedora pid
  *        objectFormat - one of dkabm, docbook, marcxchange, opensearchobject
  *        includeHoldingsCount - boolean
  *        relationData - type, uri og full
  *        repository
  */
  public function getObject($param) {
    $this->tracking_id = verbose::set_tracking_id('os', $param->trackingId->_value);
    $ret_error->searchResponse->_value->error->_value = &$error;
    if (!$this->aaa->has_right('opensearch', 500)) {
      $error = 'authentication_error';
      return $ret_error;
    }
    $repositories = $this->config->get_value('repository', 'setup');
    if (empty($param->repository->_value)) {
      $this->repository = $repositories[$this->config->get_value('default_repository', 'setup')];
    }
    elseif (!$this->repository = $repositories[$param->repository->_value]) {
      $error = 'Error: Unknown repository: ' . $param->repository->_value;
      verbose::log(FATAL, $error);
      return $ret_error;
    }
    if (empty($param->agency->_value) && empty($param->profile->_value)) {
      $param->agency->_value = $this->config->get_value('agency_fallback', 'setup');
      $param->profile->_value = $this->config->get_value('profile_fallback', 'setup');
    }
    $this->search_profile_version = $this->repository['search_profile_version'];
    if ($agency = $param->agency->_value) {
      if ($param->profile->_value) {
        if (!($this->search_profile = $this->fetch_profile_from_agency($agency, $param->profile->_value, $this->search_profile_version))) {
          $error = 'Error: Cannot fetch profile: ' . $param->profile->_value . ' for ' . $agency;
          return $ret_error;
        }
      }
      else
        $agencies = $this->config->get_value('agency', 'agency');
      $agencies[$agency] = $this->set_solr_filter($this->search_profile, $this->search_profile_version);
      if (isset($agencies[$agency]))
        $filter_agency = $agencies[$agency];
      else {
        $error = 'Error: Unknown agency: ' . $agency;
        return $ret_error;
      }
    }

    $format = $this->set_format($param->objectFormat, 
                                $this->config->get_value('open_format', 'setup'), 
                                $this->config->get_value('solr_format', 'setup'));
    $this->cache = new cache($this->config->get_value('cache_host', 'setup'),
                             $this->config->get_value('cache_port', 'setup'),
                             $this->config->get_value('cache_expire', 'setup'));

    if (is_array($param->identifier)) {
      $fpids = $param->identifier;
    }
    else {
      $fpids = array($param->identifier);
    }
    foreach ($fpids as $fpid_number => $fpid) {
      if ($this->deleted_object($fpid->_value)) {
        $error = 'Error: deleted record: ' . $fpid->_value;
        return $ret_error;
      }
      if ($error = $this->get_fedora_raw($fpid->_value, $fedora_result))
        return $ret_error;
// 2DO 
// relations are now on the unit, so this has to be found
      if ($param->relationData->_value || 
          $format['found_solr_format'] || 
          $this->xs_boolean($param->includeHoldingsCount->_value)) {
        $this->get_fedora_rels_hierarchy($fpid->_value, $fedora_rels_hierarchy);
        $unit_id = $this->parse_rels_for_unit_id($fedora_rels_hierarchy);
        if ($param->relationData->_value) {
          $this->get_fedora_rels_addi($unit_id, $fedora_addi_relation);
        }
        if ($this->xs_boolean($param->includeHoldingsCount->_value)) {
          $this->get_fedora_rels_hierarchy($unit_id, $unit_rels_hierarchy);
          list($dummy, $dummy) = $this->parse_unit_for_object_ids($unit_rels_hierarchy);
          $this->cql2solr = new cql2solr('opensearch_cql.xml', $this->config);
          $no_of_holdings = $this->get_holdings($fpid->_value);
        }
      }
//var_dump($fedora_rels_hierarchy);
//var_dump($unit_id);
//var_dump($fedora_addi_relation);
//die();
      $o->collection->_value->resultPosition->_value = $fpid_number + 1;
      $o->collection->_value->numberOfObjects->_value = 1;
      $o->collection->_value->object[]->_value =
        $this->parse_fedora_object($fedora_result,
                                   $fedora_addi_relation,
                                   $param->relationData->_value,
                                   $fpid->_value,
                                   $filter_agency,
                                   $format,
                                   $no_of_holdings);
      $collections[]->_value = $o;
      unset($o);
      $id_array[] = $unit_id;
      $work_ids[$fpid_number + 1] = array($unit_id);
    }

    if ($format['found_open_format']) {
      $this->format_records($collections, $format);
    }
    if ($format['found_solr_format']) {
      foreach ($format as $f) {
        if ($f[is_solr_format]) {
          $add_fl .= ',' . $f['format_name'];
        }
      }
      $chk_query = $this->cql2solr->cql_2_edismax('unit.id=(' . implode($id_array, ' ' . OR_OP . ' ') . ')');
      $solr_q = $this->repository['solr'] .
                '?wt=phps' .
                '&q=' . urlencode($chk_query['edismax']) .
                '&start=0' .
                '&rows=50000' .
                '&defType=edismax' .
                '&fl=unit.id' . $add_fl;
      $solr_result = $this->curl->get($solr_q);
      $solr_2_arr[] = unserialize($solr_result);
      $this->format_solr($collections, $format, $solr_2_arr, $work_ids);
    }
    $this->remove_unselected_formats($collections, $format);

    $result = &$ret->searchResponse->_value->result->_value;
    $result->hitCount->_value = count($collections);
    $result->collectionCount->_value = 1;
    $result->more->_value = 'false';
    $result->searchResult = $collections;
    $result->facetResult->_value = '';
    $result->statInfo->_value->fedoraRecordsCached->_value = $this->number_of_fedora_cached;
    $result->statInfo->_value->fedoraRecordsRead->_value = $this->number_of_fedora_calls;
    $result->statInfo->_value->time->_value = $this->watch->splittime('Total');
    $result->statInfo->_value->trackingId->_value = $this->tracking_id;

    //print_r($param);
    //print_r($fedora_result);
    //print_r($objects);
    //print_r($ret); die();
    return $ret;
  }

  /*******************************************************************************/

  private function extract_unit_id_from_solr($solr_docs, &$search_ids) {
    static $u_err = 0;
    $search_ids = array();
    foreach ($solr_docs as &$fdoc) {
      if ($uid = $fdoc['unit.id'][0]) {
        $search_ids[] = $uid;
      }
      elseif (++$u_err < 10) {
        verbose::log(FATAL, 'Missing unit.id in solr_result. Record no: ' . (count($search_ids) + $u_err));
      }
    }
  }

  private function set_format($objectFormat, $open_format, $solr_format) {
    if (is_array($objectFormat))
      $help = $objectFormat;
    elseif (empty($objectFormat->_value))
      $help[]->_value = 'dkabm';
    else
      $help[] = $objectFormat;
    foreach ($help as $of) {
      if ($open_format[$of->_value]) {
        $ret[$of->_value] = array('user_selected' => TRUE, 'is_open_format' => TRUE, 'format_name' => $open_format[$of->_value]['format']);
        $ret['found_open_format'] = TRUE;
      }
      elseif ($solr_format[$of->_value]) {
        $ret[$of->_value] = array('user_selected' => TRUE, 'is_solr_format' => TRUE, 'format_name' => $solr_format[$of->_value]['format']);
        $ret['found_solr_format'] = TRUE;
      }
      else {
        $ret[$of->_value] = array('user_selected' => TRUE, 'is_solr_format' => FALSE);
      }
    }
    if ($ret['found_open_format'] || $ret['found_solr_format']) {
      if (empty($ret['dkabm']))
        $ret['dkabm'] = array('user_selected' => FALSE, 'is_open_format' => FALSE);
      if (empty($ret['marcxchange']))
        $ret['marcxchange'] = array('user_selected' => FALSE, 'is_open_format' => FALSE);
    }
    return $ret;
  }

  /** \brief
   *
   */
  private function get_holdings($pid) {
    static $hold_ws_url;
    static $dom;
    if (empty($hold_ws_url)) {
      $hold_ws_url = $this->config->get_value('holdings_db', 'setup');
    }
    $this->watch->start('holdings');
    $hold_url = sprintf($hold_ws_url, $pid);
    $holds = $this->curl->get($hold_url);
    $this->watch->stop('holdings');
    $curl_err = $this->curl->get_status();
    if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
      verbose::log(FATAL, 'holdings_db http-error: ' . $curl_err['http_code'] . ' from: ' . $hold_url);
      $holds = array('have' => 0, 'lend' => 0);
    }
    else {
      if (empty($dom)) {
        $dom = new DomDocument();
      }
      $dom->preserveWhiteSpace = false;
      if (@ $dom->loadXML($holds)) {
        $holds = array('have' => $dom->getElementsByTagName('librariesHave')->item(0)->nodeValue,
                       'lend' => $dom->getElementsByTagName('librariesLend')->item(0)->nodeValue);
      }
    }
    return $holds;
  }

  /** \brief Pick tags from solr result and create format
   *
   */
  private function format_solr(&$collections, $format, $solr, &$work_ids, $fpid_sort_keys = array()) {
    $solr_display_ns = $this->xmlns['ds'];
    $this->watch->start('format_solr');
    foreach ($format as $format_name => $format_arr) {
      if ($format_arr['is_solr_format']) {
        $format_tags = explode(',', $format_arr['format_name']);
        foreach ($collections as $idx => &$c) {
          $rec_no = $c->_value->collection->_value->resultPosition->_value;
          foreach ($work_ids[$rec_no] as $mani_no => $unit_no) {
            if (is_array($solr[0]['response']['docs'])) {
              $fpid = $c->_value->collection->_value->object[$mani_no]->_value->identifier->_value;
              foreach ($solr[0]['response']['docs'] as $solr_doc) {
                if (is_array($solr_doc['unit.id']) && in_array($unit_no, $solr_doc['unit.id'])) {
                  foreach ($format_tags as $format_tag) {
                    if ($solr_doc[$format_tag] || $format_tag == 'fedora.identifier') {
                      if (strpos($format_tag, '.')) {
                        list($tag_NS, $tag_value) = explode('.', $format_tag);
                      }
                      else {
                        $tag_value = $format_tag;
                      }
                      $mani->_value->$tag_value->_namespace = $solr_display_ns;
                      if ($format_tag == 'fedora.identifier') {
                        $mani->_value->$tag_value->_value = $fpid;
                      }
                      else {
                        if (is_array($solr_doc[$format_tag])) {
                          $mani->_value->$tag_value->_value = $this->normalize_chars($solr_doc[$format_tag][0]);
                        }
                        else {
                          $mani->_value->$tag_value->_value = $this->normalize_chars($solr_doc[$format_tag]);
                        }
                      }
                    }
                  }
                  break;
                }
              }
            }
            if ($mani) {   // should contain data, but for some odd reason it can be empty. Some bug in the solr-indexes?
              $mani->_namespace = $solr_display_ns;
              $sort_key = $fpid_sort_keys[$fpid] . sprintf('%04d', $mani_no);
		      $manifestation->manifestation[$sort_key] = $mani;
            }
            unset($mani);
          }
// need to loop thru objects to put data correct
          if (is_array($manifestation->manifestation)) {
            ksort($manifestation->manifestation);
          }
          $c->_value->formattedCollection->_value->$format_name->_namespace = $solr_display_ns;
          $c->_value->formattedCollection->_value->$format_name->_value = $manifestation;
          unset($manifestation);
        }
      }
    }
    $this->watch->stop('format_solr');
  }

  /** \brief
   *
   */
  private function format_records(&$collections, $format) {
    $this->watch->start('format');
    foreach ($format as $format_name => $format_arr) {
      if ($format_arr['is_open_format']) {
        $f_obj->formatRequest->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->originalData = $collections;
        foreach ($f_obj->formatRequest->_value->originalData as $i => $o)
          $f_obj->formatRequest->_value->originalData[$i]->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->outputFormat->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->outputFormat->_value = $format_arr['format_name'];
        $f_obj->formatRequest->_value->outputType->_namespace = $this->xmlns['of'];
        $f_obj->formatRequest->_value->outputType->_value = 'php';
        $f_obj->formatRequest->_value->trackingId->_value = $this->tracking_id;
        $f_xml = $this->objconvert->obj2soap($f_obj);
        $this->curl->set_post($f_xml);
        $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/xml; charset=UTF-8'));
        $open_format_uri = $this->config->get_value('ws_open_format_uri', 'setup');
        $f_result = $this->curl->get($open_format_uri);
        //$fr_obj = unserialize($f_result);
        $fr_obj = $this->objconvert->set_obj_namespace(unserialize($f_result), $this->xmlns['']);
        if (!$fr_obj) {
          $curl_err = $this->curl->get_status();
          verbose::log(FATAL, 'openFormat http-error: ' . $curl_err['http_code'] . ' from: ' . $open_format_uri);
        }
        else {
          $struct = key($fr_obj->formatResponse->_value);
          foreach ($collections as $idx => &$c) {
            $c->_value->formattedCollection->_value->{$struct} = $fr_obj->formatResponse->_value->{$struct}[$idx];
          }
        }
      }
    }
    $this->watch->stop('format');
  }

  /** \brief
   *
   */
  private function remove_unselected_formats(&$collections, &$format) {
    foreach ($collections as $idx => &$c) {
      foreach ($c->_value->collection->_value->object as &$o) {
        if (!$format['dkabm']['user_selected'])
          unset($o->_value->record);
        if (!$format['marcxchange']['user_selected'])
          unset($o->_value->collection);
      }
    }
  }

  /** \brief
   *
   */
  private function deleted_object($fpid) {
    static $dom;
    $state = '';
    if ($obj_url = $this->repository['fedora_get_object_profile']) {
      $this->get_fedora($obj_url, $fpid, $obj_rec);
      if ($obj_rec) {
        if (empty($dom))
          $dom = new DomDocument();
        $dom->preserveWhiteSpace = false;
        if (@ $dom->loadXML($obj_rec))
          $state = $dom->getElementsByTagName('objState')->item(0)->nodeValue;
      }
    }
    return $state == 'D';
  }

  /** \brief
   *
   */
  private function get_fedora_raw($fpid, &$fedora_rec) {
    return $this->get_fedora($this->repository['fedora_get_raw'], $fpid, $fedora_rec);
  }

  /** \brief
   *
   */
  private function get_fedora_rels_addi($fpid, &$fedora_rel) {
    if ($this->repository['fedora_get_rels_addi']) {
      return $this->get_fedora($this->repository['fedora_get_rels_addi'], $fpid, $fedora_rel, FALSE);
    }
    else {
      return FALSE;
    }
  }

  /** \brief
   *
   */
  private function get_fedora_rels_hierarchy($fpid, &$fedora_rel) {
    return $this->get_fedora($this->repository['fedora_get_rels_hierarchy'], $fpid, $fedora_rel);
  }

  /** \brief
   *
   */
  private function get_fedora($uri, $fpid, &$rec, $mandatory=TRUE) {
    $record_uri =  sprintf($uri, $fpid);
    verbose::log(STAT, 'get_fedora: ' . $record_uri);
    if (DEBUG_ON) echo 'Fetch record: ' . $record_uri . "\n";
    if ($this->cache && $rec = $this->cache->get($record_uri)) {
      $this->number_of_fedora_cached++;
    }
    else {
      $this->number_of_fedora_calls++;
      $this->curl->set_authentication('fedoraAdmin', 'fedoraAdmin');
      $this->watch->start('fedora');
      $rec = $this->normalize_chars($this->curl->get($record_uri));
      $this->watch->stop('fedora');
      $curl_err = $this->curl->get_status();
      if ($curl_err['http_code'] < 200 || $curl_err['http_code'] > 299) {
        $rec = '';
        if ($mandatory) {
          if ($curl_err['http_code'] == 404) {
            return 'record_not_found';
          }
          verbose::log(FATAL, 'Fedora http-error: ' . $curl_err['http_code'] . ' from: ' . $record_uri);
          return 'Error: Cannot fetch record: ' . $fpid . ' - http-error: ' . $curl_err['http_code'];
        }
      }
      if ($this->cache) $this->cache->set($record_uri, $rec);
    }
    // else verbose::log(STAT, 'Fedora cache hit for ' . $fpid);
    return;
  }

  /** \brief Build Solr filter_query parm
   *
   */
  private function set_solr_filter($profile, $profile_version) {
    $ret = '';
    foreach ($profile as $p) {
      if ($profile_version == 3) {
        if ($this->xs_boolean($p['sourceSearchable']))
          $ret .= ($ret ? ' OR ' : '') .
                  'rec.collectionIdentifier:' . $p['sourceIdentifier'];
      }
      else
        $ret .= ($ret ? ' OR ' : '') .
                '(submitter:' . $p['sourceOwner'] .  
                ' AND original_format:' . $p['sourceFormat'] . ')';
    }
    return $ret;
  }

  /** \brief Check a relation against the search_profile
   *
   */
  private function check_valid_relation($from_id, $to_id, $relation, &$profile) {
    static $rels, $source;
    if (!isset($rels)) {
      $rel_from = $rel_to = array();
      foreach ($profile as $src) {
        $source[$src['sourceIdentifier']] = TRUE;
        if ($src['relation']) {
          foreach ($src['relation'] as $rel) {
            if ($rel['rdfLabel'])
              $rels[$src['sourceIdentifier']][$rel['rdfLabel']] = TRUE;
            if ($rel['rdfInverse'])
              $rels[$src['sourceIdentifier']][$rel['rdfInverse']] = TRUE;
          }
        }
      }

      if (DEBUG_ON) {
        print_r($profile);
        echo "rels:\n"; print_r($rels); echo "source:\n"; print_r($source);
      }
    }
    if (substr($to_id, 0, 5) == 'unit:') {
      $this->get_fedora_rels_hierarchy($to_id, $rels_sys);
      $to_id = $this->fetch_primary_bib_object($rels_sys);
    }
    $from = $this->kilde($from_id);
    $to = $this->kilde($to_id);
    if (DEBUG_ON) {
      echo "from: $from to: $to relation: $relation \n";
    }

    return (isset($rels[$to][$relation]));
  }

  private function kilde($id) {
    list($ret, $dummy) = explode(':', $id);
    return $ret;
  }

  /** \brief Fetch a profile $profile_name for agency $agency
   *
   */
  private function fetch_profile_from_agency($agency, $profile_name, $profile_version) {
    require_once 'OLS_class_lib/search_profile_class.php';
    if (!($host = $this->config->get_value('profile_cache_host', 'setup')))
      $host = $this->config->get_value('cache_host', 'setup');
    if (!($port = $this->config->get_value('profile_cache_port', 'setup')))
      $port = $this->config->get_value('cache_port', 'setup');
    if (!($expire = $this->config->get_value('profile_cache_expire', 'setup')))
      $expire = $this->config->get_value('cache_expire', 'setup');
    $profiles = new search_profiles($this->config->get_value('open_agency', 'setup'), $host, $port, $expire);
    $profile_version = ($profile_version ? intval($profile_version) : 2);
    $profile = $profiles->get_profile($agency, $profile_name, $profile_version);
    if (is_array($profile)) {
      return $profile;
    }
    else {
      return FALSE;
    }
  }

  /** \brief Build bq (BoostQuery) as field:content^weight
   *
   */
  public static function boostUrl($boost) {
    $ret = '';
    if ($boost) {
      $boosts = (is_array($boost) ? $boost : array($boost));
      foreach ($boosts as $bf) {
        $ret .= '&bq=' .
                urlencode($bf->_value->fieldName->_value . ':"' .
                          str_replace('"', '', $bf->_value->fieldValue->_value) . '"^' .
                          $bf->_value->weight->_value);
      }
    }
    return $ret;
  }

  /** \brief
   *
   * @param $q
   * @param $start
   * @param $rows
   * @param $sort
   * @param $facets
   * @param $filter
   * @param $boost
   * @param $debug
   * @param $solr_arr
   *
   */
  private function get_solr_array($q, $start, $rows, $sort, $rank, $facets, $filter, $boost, $debug, &$solr_arr) {
    $solr_query = $this->repository['solr'] . 
                    '?q=' . urlencode($q) . 
                    '&fq=' . $filter . 
                    '&start=' . $start . 
                    '&rows=' . $rows . $sort . $rank . $boost . $facets . 
                    ($debug ? '&debugQuery=on' : '') . 
                    '&fl=unit.id' . 
                    '&defType=edismax&wt=phps';

    //echo $solr_query;
    //exit;

    verbose::log(TRACE, 'Query: ' . $solr_query);
    verbose::log(DEBUG, 'Query: ' . $this->repository['solr'] . "?q=" . urlencode($q) . "&fq=$filter&start=$start&rows=1$sort$boost&fl=fedoraPid,unit.id$facets&defType=edismax&debugQuery=on");
    $this->curl->set_option(CURLOPT_HTTPHEADER, array('Content-Type: text/plain; charset=utf-8'));
    $solr_result = $this->curl->get($solr_query);
    if (empty($solr_result))
      return 'Internal problem: No answer from Solr';
    if (!$solr_arr = unserialize($solr_result))
      return 'Internal problem: Cannot decode Solr result';
  }

  /** \brief Parse a rels-ext record and extract the unit id
   *
   */
  private function parse_rels_for_unit_id($rels_hierarchy) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($rels_hierarchy)) {
      $imo = $dom->getElementsByTagName('isPrimaryBibObjectFor');
      if ($imo->item(0))
        return($imo->item(0)->nodeValue);
      else {
        $imo = $dom->getElementsByTagName('isMemberOfUnit');
        if ($imo->item(0))
          return($imo->item(0)->nodeValue);
      }
    }

    return FALSE;
  }

  /** \brief Parse a rels-ext record and extract the work id
   *
   */
  private function parse_rels_for_work_id($rels_hierarchy) {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($rels_hierarchy))
      $imo = $dom->getElementsByTagName('isPrimaryUnitObjectFor');
      if ($imo->item(0))
        return($imo->item(0)->nodeValue);
      else {
        $imo = $dom->getElementsByTagName('isMemberOfWork');
        if ($imo->item(0))
          return($imo->item(0)->nodeValue);
      }

    return FALSE;
  }

  /** \brief Echos config-settings
   *
   */
  public function show_info() {
    echo '<pre>';
    echo 'version             ' . $this->config->get_value('version', 'setup') . '<br/>';
    echo 'agency              ' . $this->config->get_value('open_agency', 'setup') . '<br/>';
    echo 'aaa_credentials     ' . $this->strip_oci_pwd($this->config->get_value('aaa_credentials', 'aaa')) . '<br/>';
    echo 'default_repository  ' . $this->config->get_value('default_repository', 'setup') . '<br/>';
    echo 'repository          ' . print_r($this->config->get_value('repository', 'setup'), true) . '<br/>';
    echo '</pre>';
    die();
  }

  private function strip_oci_pwd($cred) {
    if (($p1 = strpos($cred, '/')) && ($p2 = strpos($cred, '@')))
      return substr($cred, 0, $p1) . '/********' . substr($cred, $p2);
    else
      return $cred;
  }

  /** \brief Fetch id for primaryBibObject
   *
   */
  private function fetch_primary_bib_object($u_rel) {
    $arr = $this-> parse_unit_for_object_ids($u_rel);
    return $arr[0];
  }

  /** \brief Parse a work relation and return array of ids
   *
   */
  private function parse_unit_for_object_ids($u_rel) {
    static $dom;
    $res = array();
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($u_rel)) {
      $res = array();
      $hmof = $dom->getElementsByTagName('hasMemberOfUnit');
      $hpbo = $dom->getElementsByTagName('hasPrimaryBibObject');
      if ($hpbo->item(0))
        return(array($hpbo->item(0)->nodeValue, $hmof->length));
      return array(FALSE, FALSE);
    }
  }

  /** \brief Parse a work relation and return array of ids
   *
   */
  private function parse_work_for_object_ids($w_rel, $uid) {
    static $dom;
    $res = array();
    if (empty($dom)) {
      $dom = new DomDocument();
    }
    $dom->preserveWhiteSpace = false;
    if (@ $dom->loadXML($w_rel)) {
      $res = array();
      $res[] = $uid;
      //$hpuo = $dom->getElementsByTagName('hasPrimaryUnitObject');
      //if ($hpuo->item(0))
        //$res[] = $puo = $hpuo->item(0)->nodeValue;
      $r_list = $dom->getElementsByTagName('hasMemberOfWork');
      foreach ($r_list as $r) {
        if ($r->nodeValue <> $uid) $res[] = $r->nodeValue;
      }
      return $res;
    }
  }

  /** \brief Parse a fedora object and extract record and relations
   *
   * @param $fedora_obj      - the bibliographic record from fedora
   * @param $fedora_addi_obj - corresponding relation object
   * @param $rels_type       - level for returning relations
   * @param $rec_id          - record id of the record
   * @param $filter          - agency filter
   * @param $format          -
   * @param $debug_info      -
   */
  private function parse_fedora_object(&$fedora_obj, $fedora_addi_obj, $rels_type, $rec_id, $filter, $format, $holdings_count, $debug_info='') {
    static $dom;
    if (empty($dom)) {
      $dom = new DomDocument();
      $dom->preserveWhiteSpace = false;
    }
    if (@ !$dom->loadXML($fedora_obj)) {
      verbose::log(FATAL, 'Cannot load recid ' . $rec_id . ' into DomXml');
      return;
    }

    $rec = $this->extract_record($dom, $rec_id, $format);

    if (in_array($rels_type, array('type', 'uri', 'full'))) {
      $this->get_relations_from_commonData_stream($relations, $rec_id, $rels_type);
      $this->get_relations_from_addi_stream($relations, $fedora_addi_obj, $rels_type, $filter, $format);
    }

    $ret = $rec;
    $ret->identifier->_value = $rec_id;
    $ret->creationDate->_value = $this->get_creation_date($dom);
// hack
    if (empty($ret->creationDate->_value) && (strpos($rec_id, 'tsart:') || strpos($rec_id, 'avis:'))) {
      unset($holdings_count);
    }
    if (is_array($holdings_count)) {
      $ret->holdingsCount->_value = $holdings_count['have'];
      $ret->lendingLibraries->_value = $holdings_count['lend'];
    }
    if ($relations) $ret->relations->_value = $relations;
    $ret->formatsAvailable->_value = $this->scan_for_formats($dom);
    if ($debug_info) $ret->queryResultExplanation->_value = $debug_info;
    //if (DEBUG_ON) var_dump($ret);

    //print_r($ret);
    //exit;

    return $ret;
  }

  /** \brief Check if a record is searchable
   *
   */
  private function is_searchable($unit_id, $filter_q) {
// do not check for searchability, since the relation is found in the search_profile, it's ok to use it
    return TRUE;
    if (empty($filter_q)) return TRUE;

    $this->get_solr_array('unit.id:' . str_replace(':', '\:', $unit_id), 1, 0, '', '', '', rawurlencode($filter_q), '', '', $solr_arr);
    return $solr_arr['response']['numFound'];
  }

  /** \brief Check rec for available formats
   *
   */
  private function get_creation_date(&$dom) {
    if ($p = &$dom->getElementsByTagName('adminData')->item(0)) {
      return $p->getElementsByTagName('creationDate')->item(0)->nodeValue;
    }
  }

  /** \brief Check rec for available formats
   *
   */
  private function scan_for_formats(&$dom) {
    static $form_table;
    if (!isset($form_table)) {
      $form_table = $this->config->get_value('scan_format_table', 'setup');
    }

    if ($p = &$dom->getElementsByTagName('container')->item(0)) {
      foreach ($p->childNodes as $tag) {
        if ($x = &$form_table[$tag->tagName])
          $ret->format[]->_value = $x;
      }
    }

    return $ret;
  }

  /** \brief Handle relations comming from local_data streams
   *
   */
  private function get_relations_from_commonData_stream(&$relations, $rec_id, $rels_type) {
    static $stream_dom;
    $this->get_fedora_raw($rec_id, $fedora_streams);
    if (empty($stream_dom)) {
      $stream_dom = new DomDocument();
    }
    if (@ !$stream_dom->loadXML($fedora_streams)) {
      verbose::log(DEBUG, 'Cannot load STREAMS for ' . $rec_id . ' into DomXml');
    } else {
      $dub_check = array();
      foreach ($stream_dom->getElementsByTagName('link') as $link) {
        $url = $link->getelementsByTagName('url')->item(0)->nodeValue;
        if (empty($dup_check[$url])) {
          if (!$relation->relationType->_value = $link->getelementsByTagName('relationType')->item(0)->nodeValue) {
            $relation->relationType->_value = $link->getelementsByTagName('access')->item(0)->nodeValue;
          };
          if ($rels_type == 'uri' || $rels_type == 'full') {
            $relation->relationUri->_value = $url;
            $relation->linkObject->_value->accessType->_value = $link->getelementsByTagName('accessType')->item(0)->nodeValue;
            $relation->linkObject->_value->access->_value = $link->getelementsByTagName('access')->item(0)->nodeValue;
            $relation->linkObject->_value->linkTo->_value = $link->getelementsByTagName('LinkTo')->item(0)->nodeValue;
          }
          $dup_check[$url] = TRUE;
          $relations->relation[]->_value = $relation;
          unset($relation);
        }
      }
    }
  }

  /** \brief Handle relations comming from addi streams
   *
   */
  private function get_relations_from_addi_stream(&$relations, $fedora_addi_obj, $rels_type, $filter, $format) {
    static $rels_dom, $allowed_relation;
    if (!isset($allowed_relation)) {
      $allowed_relation = $this->config->get_value('relation', 'setup');
    }
    if (empty($rels_dom)) {
      $rels_dom = new DomDocument();
    }
    @ $rels_dom->loadXML($fedora_addi_obj);
    if ($rels_dom->getElementsByTagName('Description')->item(0)) {
      foreach ($rels_dom->getElementsByTagName('Description')->item(0)->childNodes as $tag) {
        if ($tag->nodeType == XML_ELEMENT_NODE) {
          if ($rel_prefix = array_search($tag->getAttribute('xmlns'), $this->xmlns))
            $this_relation = $rel_prefix . ':' . $tag->localName;
          else
            $this_relation = $tag->localName;
          $relation_type = $allowed_relation[$this_relation];
          if ($relation_type
           && $relation_count[$this_relation]++ < MAX_IDENTICAL_RELATIONS
           && $this->check_valid_relation($rec_id, $tag->nodeValue, $this_relation, $this->search_profile)) {
            if ($relation_type <> REL_TO_INTERNAL_OBJ || $this->is_searchable($tag->nodeValue, $filter)) {
              $relation->relationType->_value = $this_relation;
              if ($rels_type == 'uri' || $rels_type == 'full') {
                $this->get_fedora_rels_hierarchy($tag->nodeValue, $rels_sys);
                $rel_uri = $this->fetch_primary_bib_object($rels_sys);
                $relation->relationUri->_value = $rel_uri;
              }
              if ($rels_type == 'full' && $relation_type == REL_TO_INTERNAL_OBJ) {
                $this->get_fedora_raw($rel_uri, $related_obj);
                if (@ !$rels_dom->loadXML($related_obj)) {
                  verbose::log(FATAL, 'Cannot load ' . $rel_uri . ' object for ' . $rec_id . ' into DomXml');
                }
                else {
                  $rel_obj = &$relation->relationObject->_value->object->_value;
                  $rel_obj = $this->extract_record($rels_dom, $tag->nodeValue, $format);
                  $rel_obj->identifier->_value = $rel_uri;
                  $rel_obj->creationDate->_value = $this->get_creation_date($rels_dom);
                  $rel_obj->formatsAvailable->_value = $this->scan_for_formats($rels_dom);
                }
              }
              if ($rels_type == 'type' || $relation->relationUri->_value) {
                $relations->relation[]->_value = $relation;
              }
              unset($relation);
            }
          }
        }
      }  // foreach ...
    }
  }

  /** \brief Extract record and namespace for it
   *
   */
  private function extract_record(&$dom, $rec_id, $format) {
    foreach ($format as $format_name => $format_arr) {
      switch ($format_name) {
        case 'dkabm':
          $rec = &$ret->record->_value;
          $record = &$dom->getElementsByTagName('record');
          if ($record->item(0)) {
            $ret->record->_namespace = $record->item(0)->lookupNamespaceURI('dkabm');
          }
          if ($record->item(0)) {
            foreach ($record->item(0)->childNodes as $tag) {
//              if ($format_name == 'dkabm' || $tag->prefix == 'dc') {
                if (trim($tag->nodeValue)) {
                  if ($tag->hasAttributes()) {
                    foreach ($tag->attributes as $attr) {
                      $o->_attributes-> {$attr->localName}->_namespace = $record->item(0)->lookupNamespaceURI($attr->prefix);
                      $o->_attributes-> {$attr->localName}->_value = $attr->nodeValue;
                    }
                  }
                  $o->_namespace = $record->item(0)->lookupNamespaceURI($tag->prefix);
                  $o->_value = trim($tag->nodeValue);
                  if (!($tag->localName == 'subject' && $tag->nodeValue == 'undefined'))
                    $rec-> {$tag->localName}[] = $o;
                  unset($o);
                }
//              }
            }
          }
          else
            verbose::log(FATAL, 'No dkabm record found in ' . $rec_id);
          break;
  
        case 'marcxchange':
          $record = &$dom->getElementsByTagName('collection');
          if ($record->item(0)) {
            $ret->collection->_value = $this->xmlconvert->xml2obj($record->item(0), $this->xmlns['marcx']);
            //$ret->collection->_namespace = $record->item(0)->lookupNamespaceURI('collection');
            $ret->collection->_namespace = $this->xmlns['marcx'];
          }
          break;
  
        case 'docbook':
          $record = &$dom->getElementsByTagNameNS($this->xmlns['docbook'], 'article');
          if ($record->item(0)) {
            $ret->article->_value = $this->xmlconvert->xml2obj($record->item(0));
            $ret->article->_namespace = $record->item(0)->lookupNamespaceURI('docbook');
            //print_r($ret); die();
          }
          break;
        case 'opensearchobject':
          $record = &$dom->getElementsByTagNameNS($this->xmlns['oso'], 'object');
          if ($record->item(0)) {
            $ret->object->_value = $this->xmlconvert->xml2obj($record->item(0));
            $ret->object->_namespace = $record->item(0)->lookupNamespaceURI('oso');
            //print_r($ret); die();
          }
          break;
      }
    }
    return $ret;
  }

  private function normalize_chars($s) {
    $from[] = "\xEA\x9C\xB2"; $to[] = 'Aa';
    $from[] = "\xEA\x9C\xB3"; $to[] = 'aa';
    $from[] = "\XEF\x83\xBC"; $to[] = "\xCC\x88";   // U+F0FC -> U+0308
    return str_replace($from, $to, $s);
  }

  /** \brief Parse solr facets and build reply
  *
  * array('facet_queries' => ..., 'facet_fields' => ..., 'facet_dates' => ...)
  *
  * return:
  * facet(*)
  * - facetName
  * - facetTerm(*)
  *   - frequence
  *   - term
  */
  private function parse_for_facets(&$facets) {
    if ($facets['facet_fields']) {
      foreach ($facets['facet_fields'] as $facet_name => $facet_field) {
        $facet->facetName->_value = $facet_name;
        foreach ($facet_field as $term => $freq) {
          if ($term && $freq) {
            $o->frequence->_value = $freq;
            $o->term->_value = $term;
            $facet->facetTerm[]->_value = $o;
            unset($o);
          }
        }
        $ret->facet[]->_value = $facet;
        unset($facet);
      }
    }
    return $ret;
  }

  /** \brief
   *  return true if xs:boolean is so
   */
  private function xs_boolean($str) {
    return (strtolower($str) == 'true' || $str == 1);
  }

}

/*
 * MAIN
 */

if (!defined('PHPUNIT_RUNNING')) {
  $ws=new openSearch();

  $ws->handle_request();
}
