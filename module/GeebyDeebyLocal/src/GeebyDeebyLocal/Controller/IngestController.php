<?php
/**
 * Ingest controller
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
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
namespace GeebyDeebyLocal\Controller;
use GeebyDeebyLocal\Ingest\ModsExtractor, Zend\Console\Console, Zend\Console\Prompt;

/**
 * Ingest controller
 *
 * @category GeebyDeeby
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class IngestController extends \GeebyDeeby\Controller\AbstractBase
{
    // constant values drawn from dimenovels.org database:
    const FULLTEXT_SOURCE_NIU = 10;
    const MATERIALTYPE_WORK = 1;
    const PREDICATE_OWL_SAMEAS = 2;
    const ROLE_AUTHOR = 1;
    const TAGTYPE_LC = 1;

    protected $settings;

    public function __construct()
    {
        $this->settings = json_decode(file_get_contents(__DIR__ . '/settings.json'));
    }

    /**
     * Ingest existing editions (by matching URIs) action.
     *
     * @return mixed
     */
    public function existingAction()
    {
        foreach ($this->getExistingEditionsFromSolr() as $edition) {
            if (!$this->loadExistingEdition($edition)) {
                break;
            }
        }
    }

    /**
     * Ingest series entries (by searching series title) action.
     *
     * @return mixed
     */
    public function seriesAction()
    {
        // TODO
        $series = $this->params()->fromRoute('series');
        $seriesObj = $this->getSeriesByTitle($series);
        if (!$seriesObj) {
            Console::writeLine("Cannot find series match for $series");
            return;
        }
        foreach ($this->getSeriesEntriesFromSolr($series) as $pid) {
            if (!$this->loadSeriesEntry($pid, $seriesObj)) {
                break;
            }
        }
    }

    protected function loadSeriesEntry($pid, $seriesObj)
    {
        Console::writeLine("Loading series entry (pid = $pid)");
        $rawMods = $this->getModsForPid($pid, '/tmp/gbdb_pid_' . md5($pid));
        if (!$rawMods) {
            Console::writeLine('Could not retrieve MODS.');
            return false;
        }
        $mods = simplexml_load_string($rawMods);
        $extractor = new ModsExtractor();
        $details = $extractor->getDetails($mods);
        if (count($details['contents']) > 1) {
            // For now, we expect just one work per item; TODO: multi-part issues
            Console::writeLine('FATAL: More contents than expected....');
            return false;
        }
        $pos = preg_replace('/[^0-9]/', '', current($details['series']));
        $childDetails = $this->synchronizeSeriesEntries($seriesObj, $pos, $details['contents']);
        if (!$childDetails) {
            return false;
        }
        $childDetails = $this->addAuthorDetails($childDetails);
        if (!$childDetails) {
            return false;
        }
        $details['contents'] = $childDetails;
        var_dump($details); die();
        return true;
    }

    protected function getSeriesByTitle($title)
    {
        $table = $this->getDbTable('series');
        $result = $table->select(['Series_Name' => $title]);
        if (count($result) != 1) {
            Console::writeLine('Unexpected result count: ' . count($result));
            return false;
        }
        foreach ($result as $current) {
            return $current;
        }
    }

    protected function loadExistingEdition($edition)
    {
        Console::writeLine("Loading existing edition (id = {$edition})");
        $rawMods = $this->getModsForEdition('http://dimenovels.org/Edition/' . $edition);
        if (!$rawMods) {
            Console::writeLine('Could not retrieve MODS.');
            return false;
        }
        $mods = simplexml_load_string($rawMods);
        $editionObj = $this->getDbTable('edition')->getByPrimaryKey($edition);
        $series = $this->getSeriesForEdition($editionObj);

        $extractor = new ModsExtractor();
        $details = $extractor->getDetails($mods);

        if (!$this->validateSeries($details, $editionObj, $series)) {
            Console::writeLine('Series validation failed.');
            return false;
        }

        $childDetails = $this->synchronizeChildren($editionObj, $details['contents']);
        if (!$childDetails) {
            return false;
        }
        $childDetails = $this->addAuthorDetails($childDetails);
        if (!$childDetails) {
            return false;
        }
        $details['contents'] = $childDetails;
        return $this->updateDatabase($editionObj, $series, $details);
    }

    protected function updateDatabase($editionObj, $series, $details)
    {
        $item = $this->getItemForEdition($editionObj);
        if (isset($details['date'])) {
            if (!$this->processDate($details['date'], $editionObj)) {
                return false;
            }
        }
        if (isset($details['publisher'])) {
            if (!$this->processPublisher($details['publisher'], $editionObj, $series['Series_ID'])) {
                return false;
            }
        }
        if (isset($details['oclc'])) {
            if (!$this->processOclcNum($details['oclc'], $editionObj)) {
                return false;
            }
        }
        if (isset($details['url'])) {
            if (!$this->processUrl($details['url'], $editionObj)) {
                return false;
            }
        }
        return $this->updateWorks($editionObj, $details['contents']);
    }

    protected function processDate($date, $editionObj)
    {
        list($year, $month, $day) = explode('-', $date);
        $table = $this->getDbTable('editionsreleasedates');
        $known = $table->getDatesForEdition($editionObj->Edition_ID);
        $foundMatch = false;
        foreach ($known as $current) {
            if ($current->Month == $month && $current->Year == $year && $current->Day == $day) {
                $foundMatch = true;
            }
        }
        if (!$foundMatch && count($known) > 0) {
            Console::writeLine("FATAL: Unexpected date value in database; expected $date.");
            return false;
        }
        if (count($known) == 0) {
            Console::writeLine("Adding date: {$date}");
            $table->insert(
                [
                    'Edition_ID' => $editionObj->Edition_ID,
                    'Year' => $year,
                    'Month' => $month,
                    'Day' => $day
                ]
            );
        }
        return true;
    }

    protected function separateNameAndStreet($publisher)
    {
        $parts = array_map('trim', explode(',', $publisher));
        $name = array_shift($parts);
        $skipParts = ['Publisher', 'Publishers', 'Inc.'];
        while (isset($parts[0]) && in_array($parts[0], $skipParts)) {
            array_shift($parts);
        }
        $street = implode(', ', $parts);
        return [$name, $street];
    }

    protected function normalizeStreet($street)
    {
        return str_replace(
            [' st.', ' w.', '23rd'],
            [' street', ' west', '23d'],
            strtolower($street)
        );
    }
    protected function streetsMatch($street1, $street2)
    {
        return $this->normalizeStreet($street1) == $this->normalizeStreet($street2);
    }

    protected function processPublisher($publisher, $editionObj, $seriesId)
    {
        list ($name, $street) = $this->separateNameAndStreet($publisher['name']);
        if (empty($street)) {
            Console::writeLine("WARNING: No street address; skipping publisher.");
            return true;
        }
        $place = $publisher['place'];
        $spTable = $this->getDbTable('seriespublishers');
        $cityTable = $this->getDbTable('city');
        $pubTable = $this->getDbTable('publisher');
        $result = $spTable->getPublishers($seriesId);
        $match = false;
        foreach ($result as $current) {
            $city = $current['City_ID'] ? $cityTable->getByPrimaryKey($current['City_ID']) : false;
            $pub = $current['Publisher_ID'] ? $pubTable->getByPrimaryKey($current['Publisher_ID']) : false;
            if ($city && $place == $city->City_Name
                && $pub && $name == $pub->Publisher_Name
                && $this->streetsMatch($street, $current->Street)
            ) {
                $match = $current->Series_Publisher_ID;
                break;
            }
        }
        if (!$match) {
            Console::writeLine("FATAL: No series/publisher match for $name, $street, $place");
            return false;
        }
        if ($editionObj->Preferred_Series_Publisher_ID && $editionObj->Preferred_Series_Publisher_ID != $match) {
            foreach ($this->getDbTable('edition')->getPublishersForEdition($editionObj->Edition_ID) as $ed);
            Console::writeLine("Publisher mismatch in edition.");
            Console::writeLine("Old: {$ed['Publisher_Name']}, {$ed['Street']}, {$ed['City_Name']}");
            Console::writeLine("New: $name, $street, $place");
            if (!Prompt\Confirm::prompt('Change? (y/n) ')) {
                Console::writeLine("FATAL: Aborting ingest due to publisher mismatch.");
                return false;
            }
        }
        if ($editionObj->Preferred_Series_Publisher_ID && $editionObj->Preferred_Series_Publisher_ID == $match) {
            return true;
        }
        Console::writeLine("Updating address to $name, $street, $place");
        $editionObj->Preferred_Series_Publisher_ID = $match;
        $editionObj->save();
        return true;
    }

    protected function processOclcNum($oclc, $editionObj)
    {
        // strip off non-digits (useless OCLC prefixes):
        foreach ($oclc as $i => $current) {
            $oclc[$i] = preg_replace('/[^0-9]/', '', $current);
        }
        $table = $this->getDbTable('editionsoclcnumbers');
        $known = $table->getOCLCNumbersForEdition($editionObj->Edition_ID);
        $knownArr = [];
        foreach ($known as $current) {
            $knownArr[] = $current->OCLC_Number;
        }
        foreach (array_diff($oclc, $knownArr) as $current) {
            Console::writeLine("Adding OCLC number: {$current}");
            $table->insert(
                [
                    'Edition_ID' => $editionObj->Edition_ID,
                    'OCLC_Number' => $current
                ]
            );
        }
        return true;
    }

    protected function processUrl($urls, $editionObj)
    {
        // check for unexpected URLs (right now we assume everything is from NIU):
        foreach ($urls as $current) {
            if (!strstr($current, 'lib.niu.edu')) {
                Console::writeLine('FATAL: Unexpected URL: ' . $current);
                return false;
            }
        }
        $table = $this->getDbTable('editionsfulltext');
        $known = $table->getFullTextForEdition($editionObj->Edition_ID);
        $knownArr = [];
        foreach ($known as $current) {
            $knownArr[] = $current->Full_Text_URL;
        }
        foreach (array_diff($urls, $knownArr) as $current) {
            Console::writeLine("Adding URL: {$current}");
            $table->insert(
                [
                    'Edition_ID' => $editionObj->Edition_ID,
                    'Full_Text_URL' => $current,
                    'Full_Text_Source_ID' => self::FULLTEXT_SOURCE_NIU,
                ]
            );
        }
        return true;
    }

    protected function processAuthors($ids, $db)
    {
        if ($this->hasAuthorProblem($ids, $db['authorIds'])) {
            return false;
        }
        $table = $this->getDbTable('editionscredits');
        foreach (array_diff($ids, $db['authorIds']) as $current) {
            Console::writeLine("Attaching author ID $current");
            $table->insert(
                [
                    'Edition_ID' => $db['edition']['Edition_ID'],
                    'Person_ID' => $current,
                    'Role_ID' => self::ROLE_AUTHOR,
                ]
            );
        }
        return true;
    }

    protected function subjectUrisToIds($subjects)
    {
        $tagsUris = $this->getDbTable('tagsuris');
        $tags = $this->getDbTable('tag');

        $ids = [];
        foreach ($subjects as $uri => $text) {
            $uriLookup = $tagsUris->getTagsForURI($uri);
            $id = false;
            foreach ($uriLookup as $id) {
                break;
            }
            if ($id) {
                $ids[$uri] = $id->Tag_ID;
            } else {
                if (!stristr($uri, 'loc.gov')) {
                    Console::writeLine('FATAL: Unexpected subject URI: ' . $uri);
                    return false;
                }
                $tagObj = false;
                $result = $tags->select(['Tag' => $text]);
                foreach ($result as $tagObj) {
                    break;
                }
                if ($tagObj) {
                    Console::writeLine("Upgrading subject: $text");
                    $tagObj->Tag_Type_ID = self::TAGTYPE_LC;
                    $tagObj->save();
                    $ids[$uri] = $tagObj->Tag_ID;
                } else {
                    Console::writeLine("Adding subject: $text");
                    $tags->insert(
                        [
                            'Tag' => $text,
                            'Tag_Type_ID' => self::TAGTYPE_LC,
                        ]
                    );
                    $ids[$uri] = $tags->getLastInsertValue();
                }
                $tagsUris->insert(
                    [
                        'Tag_ID' => $ids[$uri],
                        'URI' => $uri,
                        'Predicate_ID' => self::PREDICATE_OWL_SAMEAS
                    ]
                );
            }
        }
        return $ids;
    }

    protected function processAltTitles($title, $altTitles, $db)
    {
        $articleHelper = $this->getServiceLocator()->get('GeebyDeeby\Articles');
        $allTitles = array_unique(array_merge($altTitles, [$title]));
        $filteredTitles = [];
        foreach (array_diff($altTitles, [$title]) as $currentNeedle) {
            $matched = false;
            if (stristr($currentNeedle, 'and other stories')) {
                // we don't want any "and other stories" titles in this context...
                continue;
            }
            foreach ($allTitles as $currentHaystack) {
                if ($currentNeedle == $currentHaystack) {
                    continue;
                }
                if ($this->fuzzyContains($currentHaystack, $currentNeedle)
                    || $this->fuzzyContains($currentHaystack, $articleHelper->formatTrailingArticles($currentNeedle))
                ) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $filteredTitles[] = $currentNeedle;
            }
        }
        if (!empty($filteredTitles)) {
            $item = $db['item']['Item_ID'];
            $table = $this->getDbTable('itemsalttitles');
            $result = $table->getAltTitles($item);
            $existing = [];
            foreach ($result as $current) {
                $existing[] = $current->Item_AltName;
            }
            foreach ($filteredTitles as $newTitle) {
                $skip = false;
                foreach ($existing as $current) {
                    if ($this->fuzzyCompare($newTitle, $current)) {
                        $skip = true;
                        break;
                    }
                }
                if (!$skip) {
                    $table->insert(['Item_ID' => $item, 'Item_AltName' => $newTitle]);
                    Console::writeLine('Added alternate title: ' . $newTitle);
                }
            }
        }
        return true;
    }

    protected function processSubjects($subjects, $db)
    {
        $item = $db['item']['Item_ID'];
        $subjectIds = $this->subjectUrisToIds($subjects);
        if (false === $subjectIds) {
            return false;
        }
        $itemsTags = $this->getDbTable('itemstags');
        $existingTags = $itemsTags->getTags($item);
        $existingIds = [];
        foreach ($existingTags as $current) {
            $existingIds[] = $current->Tag_ID;
        }
        $missing = array_diff($subjectIds, $existingIds);
        if (count($missing) > 0) {
            Console::writeLine("Adding subject IDs: " . implode(', ', $missing));
            foreach ($missing as $id) {
                $itemsTags->insert(['Item_ID' => $item, 'Tag_ID' => $id]);
            }
        }
        return true;
    }

    protected function updateWorks($editionObj, $details)
    {
        foreach ($details as $i => $current) {
            list($data, $db) = $current;
            if (!$db) {
                if (!$this->addChildWorkToDatabase($editionObj, $data, $i)) {
                    return false;
                }
            } else {
                Console::writeLine("Processing edition ID {$db['edition']['Edition_ID']}");
                if (!$this->updateWorkInDatabase($data, $db)) {
                    return false;
                }
            }
        }
        return true;
    }

    protected function getPersonIdsForItem($item)
    {
        $table = $this->getDbTable('editionscredits');
        $ids = [];
        foreach ($table->getCreditsForItem($item) as $credit) {
            if ($credit->Role_ID == self::ROLE_AUTHOR) {
                $ids[] = $credit->Person_ID;
            }
        }
        return $ids;
    }

    protected function getItemForNewEdition($data)
    {
        // trim article for search purposes
        $strippedTitle = ($pos = strrpos($data['title'], ','))
            ? substr($data['title'], 0, $pos) : $data['title'];
        $table = $this->getDbTable('item');

        $callback = function ($select) use ($strippedTitle) {
            $select->where->like('Item_Name', $strippedTitle . '%');
        };
        $options = $table->select($callback);
        foreach ($options as $current) {
            $currentCredits = $this->getPersonIdsForItem($current->Item_ID);
            if (count($data['authorIds']) > 0
                && count(array_diff($data['authorIds'], $currentCredits) == 0)
                && count(array_intersect($data['authorIds'], $currentCredits)) == count($data['authorIds'])
            ) {
                Console::writeLine("Matched existing item ID {$current->Item_ID}");
                return $current->Item_ID;
            }
        }

        // If we made it this far, we need to create a new item.
        $table->insert(['Item_Name' => $data['title'], 'Material_Type_ID' => self::MATERIALTYPE_WORK]);
        $id = $table->getLastInsertValue();
        Console::writeLine("Added item ID {$id} ({$data['title']})");
        return $id;
    }

    protected function addChildWorkToDatabase($parentEdition, $data, $pos = 0)
    {
        $item = $this->getItemForNewEdition($data);
        $edName = $parentEdition->Edition_Name;
        $seriesID = $parentEdition->Series_ID;
        $edsTable = $this->getDbTable('edition');
        $edsTable->insert(
            [
                'Edition_Name' => $edName,
                'Series_ID' => $seriesID,
                'Item_ID' => $item,
                'Parent_Edition_ID' => $parentEdition->Edition_ID,
                'Position_In_Parent' => $pos,
            ]
        );
        $newObj = $edsTable->getByPrimaryKey($edsTable->getLastInsertValue());
        Console::writeLine("Added edition ID " . $newObj->Edition_ID);
        $this->updateWorkInDatabase(
            $data,
            [
                'edition' => $newObj,
                'authorIds' => [],
                'item' => $this->getItemForEdition($newObj)
            ]
        );
        return true;
    }

    protected function processTitle($title, $db)
    {
        if (!$this->fuzzyCompare($title, $db['item']['Item_Name'])) {
            Console::writeLine("Unexpected title mismatch; {$title} vs. {$db['item']['Item_Name']}");
            return false;
        }
        return true;
    }

    protected function processExtent($extent, $db)
    {
        $ed = $db['edition'];
        if (!empty($ed->Extent_In_Parent) && $ed->Extent_In_Parent !== $extent) {
            Console::writeLine("FATAL ERROR: Unexpected extent: " . $extent);
            return false;
        }
        if (empty($ed->Parent_Edition_ID)) {
            Console::writeLine("FATAL ERROR: Missing parent ID.");
            return false;
        }
        if (empty($ed->Extent_In_Parent)) {
            Console::writeLine('Adding extent: ' . $extent);
            $ed->Extent_In_Parent = $extent;
            $ed->save();
        }
        return true;
    }

    protected function updateWorkInDatabase($data, $db)
    {
        if (!$this->processTitle($data['title'], $db)) {
            return false;
        }
        if (isset($data['altTitles'])) {
            if (!$this->processAltTitles($data['title'], $data['altTitles'], $db)) {
                return false;
            }
        }
        if (isset($data['extent']) && !empty($data['extent'])) {
            if (!$this->processExtent($data['extent'], $db)) {
                return false;
            }
        }
        if (isset($data['authorIds'])) {
            if (!$this->processAuthors($data['authorIds'], $db)) {
                return false;
            }
        }
        if (isset($data['subjects'])) {
            if (!$this->processSubjects($data['subjects'], $db)) {
                return false;
            }
        }
        return true;
    }

    protected function addAuthorDetails($details)
    {
        foreach ($details as & $match) {
            $match[0]['authorIds'] = [];
            if (isset($match[0]['authors'])) {
                foreach ($match[0]['authors'] as $current) {
                    if (!isset($current['uri'])) {
                        Console::writeLine("FATAL: Missing URI for {$current['name']}...");
                        return false;
                    }
                    $id = $this->getPersonIdForUri($current['uri']);
                    if (!$id) {
                        Console::writeLine("FATAL: Missing Person ID for {$current['uri']}");
                        return false;
                    }
                    $match[0]['authorIds'][] = $id;
                }
            }
            if ($match[1]) {
                $credits = $this->getDbTable('editionscredits')
                    ->getCreditsForEdition($match[1]['edition']->Edition_ID);
                $match[1]['authorIds'] = [];
                foreach ($credits as $credit) {
                    if ($credit->Role_ID == self::ROLE_AUTHOR) {
                        $match[1]['authorIds'][] = $credit->Person_ID;
                    }
                }
            }
        }
        return $details;
    }

    protected function hasAuthorProblem($incomingList, $storedList)
    {
        $unexpected = array_diff($storedList, $incomingList);
        if (count($unexpected) > 0) {
            Console::writeLine("Found unexpected author ID(s) in database: " . implode(', ', $unexpected));
            return true;
        }
        return false;
    }

    protected function getPersonIdForUri($uri)
    {
        $base = 'http://dimenovels.org/Person/';
        if (substr($uri, 0, strlen($base)) == $base) {
            $id = str_replace($base, '', $uri);
        } else {
            $table = $this->getDbTable('peopleuris');
            $result = $table->select(['URI' => $uri]);
            $id = false;
            foreach ($result as $curr) {
                $id = $curr['Person_ID'];
            }
        }
        return $id;
    }

    protected function synchronizeSeriesEntries($seriesObj, $pos, $contents)
    {
        $lookup = $this->getDbTable('edition')
            ->select(['Series_ID' => $seriesObj->Series_ID, 'Position' => $pos]);
        $children = [];
        foreach ($lookup as $child) {
            $children[] = [
                'edition' => $child,
                'item' => $this->getItemForEdition($child)
            ];
        }

        $result = [];
        foreach ($contents as $currentContent) {
            $match = false;
            foreach ($children as & $currentChild) {
                if ($this->checkItemTitle($currentChild['item'], $currentContent['title'])) {
                    $match = true;
                    $result[] = [$currentContent, $currentChild];
                    $currentChild['matched'] = true;
                    break;
                }
            }
            if (!$match) {
                $result[] = [$currentContent, false];
            }
        }

        // Fail if we have any existing data not matched up with new data....
        foreach ($children as $child) {
            if (!isset($child['matched'])) {
                Console::writeLine("FATAL: No match found for edition {$child['edition']->Edition_ID}");
                return false;
            }
        }
        return $result;
    }

    protected function synchronizeChildren($editionObj, $contents)
    {
        $lookup = $this->getDbTable('edition')
            ->select(['Parent_Edition_ID' => $editionObj->Edition_ID]);
        $children = [];
        foreach ($lookup as $child) {
            $children[] = [
                'edition' => $child,
                'item' => $this->getItemForEdition($child)
            ];
        }

        $result = [];
        foreach ($contents as $currentContent) {
            $match = false;
            foreach ($children as & $currentChild) {
                if ($this->checkItemTitle($currentChild['item'], $currentContent['title'])) {
                    $match = true;
                    $result[] = [$currentContent, $currentChild];
                    $currentChild['matched'] = true;
                    break;
                }
            }
            if (!$match) {
                $result[] = [$currentContent, false];
            }
        }

        // Fail if we have any existing data not matched up with new data....
        foreach ($children as $child) {
            if (!isset($child['matched'])) {
                Console::writeLine("FATAL: No match found for edition {$child['edition']->Edition_ID}");
                return false;
            }
        }
        return $result;
    }

    protected function validateSeries($details, $editionObj, $series)
    {
        if (!isset($details['series'])) {
            Console::writeLine('No series found.');
            return false;
        }
        $expectedNumber = intval($editionObj->Position);
        foreach ($details['series'] as $seriesName => $number) {
            $actualNumber = intval(preg_replace('/[^0-9]/', '', $number));
            //Console::writeLine("Comparing {$expectedNumber} to {$actualNumber}...");
            if ($actualNumber == $expectedNumber && $this->checkSeriesTitle($series, $seriesName)) {
                return true;
            }
        }
        return false;
    }

    protected function fuzz($str)
    {
        $regex = '/[^a-z0-9]/';
        return preg_replace($regex, '', strtolower($str));
    }

    protected function fuzzyCompare($str1, $str2)
    {
        //Console::writeLine("Comparing {$str1} to {$str2}...");
        return $this->fuzz($str1) == $this->fuzz($str2);
    }

    protected function fuzzyContains($haystack, $needle)
    {
        return strstr($this->fuzz($haystack), $this->fuzz($needle));
    }

    protected function checkItemTitle($item, $title)
    {
        $itemTitle = (isset($item['Item_AltName']) && !empty($item['Item_AltName']))
            ? $item['Item_AltName'] : $item['Item_Name'];
        return $this->fuzzyCompare($title, $itemTitle);
    }

    protected function checkSeriesTitle($series, $title)
    {
        $seriesTitle = (isset($series['Series_AltName']) && !empty($series['Series_AltName']))
            ? $series['Series_AltName'] : $series['Series_Name'];
        return $this->fuzzyCompare($title, $seriesTitle);
    }

    protected function getItemForEdition($rowObj)
    {
        $itemTable = $this->getDbTable('item');
        $itemObj = $itemTable->getByPrimaryKey($rowObj->Item_ID);
        $item = $itemObj->toArray();
        if (!empty($rowObj->Preferred_Item_AltName_ID)) {
            $ian = $this->getDbTable('itemsalttitles');
            $tmpRow = $ian->select(
                array('Sequence_ID' => $rowObj->Preferred_Item_AltName_ID)
            )->current();
            $item['Item_AltName'] = $tmpRow['Item_AltName'];
        }
        return $item;
    }

    protected function getSeriesForEdition($rowObj)
    {
        $seriesTable = $this->getDbTable('series');
        $seriesObj = $seriesTable->getByPrimaryKey($rowObj->Series_ID);
        $series = $seriesObj->toArray();
        if (!empty($rowObj->Preferred_Series_AltName_ID)) {
            $san = $this->getDbTable('seriesalttitles');
            $tmpRow = $san->select(
                array('Sequence_ID' => $rowObj->Preferred_Series_AltName_ID)
            )->current();
            $series['Series_AltName'] = $tmpRow['Series_AltName'];
        }
        return $series;
    }

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

    protected function getExistingEditionsFromSolr()
    {
        $query = $this->settings->solrQueryField . ':"http://dimenovels.org/*"';
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

    protected function getSeriesEntriesFromSolr($series)
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

    protected function getModsForPid($pid, $cache = false)
    {
        if (!$pid) {
            return false;
        }

        // Retrieve MODS from repository:
        if ($cache && file_exists($cache)) {
            return file_get_contents($cache);
        }
        $modsUrl = sprintf($this->settings->modsUrl, $pid);
        Console::writeLine("Retrieving $modsUrl...");
        $mods = file_get_contents($modsUrl);
        if ($cache && $mods) {
            file_put_contents($cache, $mods);
        }
        return $mods;
    }

    protected function getModsForEdition($edition)
    {
        $cache = '/tmp/gbdb_' . md5($edition);
        if (file_exists($cache)) {
            return file_get_contents($cache);
        }

        // Get MODS identifier from Solr:
        $query = $this->settings->solrQueryField . ':"' . $edition . '"';
        $field = $this->settings->solrIdField;
        $url = (string)$this->settings->solrUrl . '?q=' . urlencode($query) . '&wt=json'
            . '&fl=' . urlencode($field);
        Console::writeLine("Querying {$this->settings->solrUrl} for $query...");
        $solr = json_decode(file_get_contents($url));
        $pid = isset($solr->response->docs[0]->$field)
            ? $solr->response->docs[0]->$field : false;
        return $this->getModsForPid($pid, $cache);
    }
}
