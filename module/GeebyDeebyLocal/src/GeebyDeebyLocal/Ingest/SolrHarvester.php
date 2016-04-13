<?php
/**
 * Solr Harvester
 *
 * PHP version 5
 *
 * Copyright (C) Demian Katz 2012.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category GeebyDeeby
 * @package  Ingest
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
namespace GeebyDeebyLocal\Ingest;
use Zend\Console\Console;

/**
 * Solr Harvester
 *
 * @category GeebyDeeby
 * @package  Ingest
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class SolrHarvester
{
    /**
     * Settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Constructor
     *
     * @param array $settings Settings
     */
    public function __construct($settings = [])
    {
        $this->settings = $settings;
    }

    /**
     * Retrieve from Solr all records matching existing editions on dimenovels.org.
     *
     * @return array Edition IDs
     */
    public function getExistingEditions()
    {
        $query = $this->settings->solrQueryField . ':"https://dimenovels.org/*"';
        $field = $this->settings->solrQueryField;
        $solr = $this->querySolr($query, $field);
        $editions = [];
        foreach ($solr->response->docs as $current) {
            $parts = explode('/', $current->{$this->settings->solrQueryField});
            $currentEd = array_pop($parts);
            $editions[] = $currentEd;
        }
        return $editions;
    }

    /**
     * Given an edition, retrieve a PID. Return false if no match found.
     *
     * @param string $edition Edition
     *
     * @return string|bool
     */
    public function getPidForEdition($edition)
    {
        // Get MODS identifier from Solr:
        $query = $this->settings->solrQueryField . ':"' . $edition . '"';
        $field = $this->settings->solrIdField;
        $solr = $this->querySolr($query, $field);
        return isset($solr->response->docs[0]->$field)
            ? $solr->response->docs[0]->$field : false;
    }

    /**
     * Retrieve Fedora PIDs matching a given series name.
     *
     * @param string $series Series to retrieve.
     *
     * @return array Fedora PIDs.
     */
    public function getSeriesEntries($series)
    {
        $query = $this->settings->solrSeriesField . ':"' . addcslashes($series, '"') . '"';
        $field = $this->settings->solrIdField;
        $solr = $this->querySolr($query, $field);
        $retVal = [];
        foreach ($solr->response->docs as $doc) {
            $pid = isset($doc->$field) ? $doc->$field : false;
            if ($pid) {
                $retVal[] = $pid;
            }
        }
        return $retVal;
    }

    /**
     * Perform a Solr query.
     *
     * @param string $query Query to execute
     * @param string $fl    Field list to retrieve
     *
     * @return object
     */
    protected function querySolr($query, $fl)
    {
        $url = (string)$this->settings->solrUrl . '?q=' . urlencode($query) . '&wt=json'
            . '&rows=10000&fl=' . urlencode($fl);
        $cache = '/tmp/gbdb_' . md5("$query-$fl");
        if (!file_exists($cache)) {
            Console::writeLine("Querying {$this->settings->solrUrl} for $query...");
            $solrResponse = file_get_contents($url);
            file_put_contents($cache, $solrResponse);
        } else {
            $solrResponse = file_get_contents($cache);
        }
        return json_decode($solrResponse);
    }
}
