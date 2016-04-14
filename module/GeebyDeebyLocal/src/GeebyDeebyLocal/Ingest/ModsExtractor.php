<?php
/**
 * MODS Extractor
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

/**
 * MODS Extractor
 *
 * @category GeebyDeeby
 * @package  Ingest
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class ModsExtractor
{
    public function getDetails($mods)
    {
        $contents = [];
        foreach ($mods->xpath('/mods:mods') as $current) {
            $currentDetails = $this->extractDetails($current);
            if (!empty($currentDetails)) {
                $contents[] = $currentDetails;
            }
        }
        foreach ($mods->xpath('/mods:mods/mods:relatedItem[@type="constituent"]') as $current) {
            $currentDetails = $this->extractDetails($current);
            if (!empty($currentDetails)) {
                $contents[] = $currentDetails;
            }
        }
        $retVal = compact('contents');
        if ($pub = $this->extractPublisher($mods->xpath('/mods:mods/mods:originInfo[@eventType="publication"]'))) {
            $retVal['publisher'] = $pub;
        }
        $date = $mods->xpath('/mods:mods/mods:originInfo[@eventType="publication"]/mods:dateIssued');
        if (isset($date[0])) {
            $retVal['date'] = (string) $date[0];
        }
        if ($seriesInfo = $this->extractSeries($mods->xpath('/mods:mods/mods:relatedItem[@type="series"]/mods:titleInfo'))) {
            $retVal['series'] = $seriesInfo;
        }
        if ($oclc = $this->extractAll($mods->xpath('/mods:mods/mods:identifier[@type="oclc"]'))) {
            $retVal['oclc'] = $oclc;
        }
        if ($url = $this->extractAll($mods->xpath('/mods:mods/mods:location/mods:url'))) {
            $retVal['url'] = $url;
        }
        return $retVal;
    }

    protected function extractAll($mods)
    {
        $all = [];
        foreach ($mods as $current) {
            $current = (string) $current;
            if (!empty($current)) {
                $all[] = $current;
            }
        }
        return empty($all) ? false : $all;
    }

    protected function extractSeries($series)
    {
        $seriesInfo = [];
        foreach ($series as $current) {
            $name = $current->xpath('mods:title');
            $number = $current->xpath('mods:partNumber');
            $firstName = isset($name[0]) ? (string) $name[0] : null;
            if (!empty($firstName)) {
                $seriesInfo[$firstName] = isset($number[0]) ? (string) $number[0] : '';
            }
        }
        return empty($seriesInfo) ? false : $seriesInfo;
    }

    protected function extractPublisher($mods)
    {
        if (!isset($mods[0])) {
            return false;
        }
        $mods = $mods[0];
        $pub = [];
        $publisher = $mods->xpath('mods:publisher');
        if (isset($publisher[0])) {
            $pub['name'] = (string) $publisher[0];
            $place = $mods->xpath('mods:place/mods:placeTerm[@type="text"]');
            if (isset($place[0])) {
                $pub['place'] = (string) $place[0];
            }
        }
        return empty($pub) ? false : $pub;
    }
    
    protected function extractDetails($mods)
    {
        $details = [];
        $title = $this->extractTitleInfo($mods);
        if (!empty($title)) {
            $details['title'] = $title;
        }
        $altTitles = $this->extractAltTitleInfo($mods);
        if (!empty($altTitles)) {
            $details['altTitles'] = $altTitles;
        }
        $authors = $this->extractAuthors($mods);
        if (!empty($authors)) {
            $details['authors'] = $authors;
        }
        $extent = $this->extractExtent($mods);
        if (!empty($extent)) {
            $details['extent'] = $extent;
        }
        $subjects = $this->extractSubjects($mods);
        if (!empty($subjects)) {
            $details['subjects'] = $subjects;
        }
        return $details;
    }

    protected function extractAuthors($mods)
    {
        $authors = [];
        $matches = $mods->xpath('mods:name');
        foreach ($matches as $current) {
            $role = $current->xpath('mods:role/mods:roleTerm');
            if (isset($role[0]) && (string)$role[0] == 'author') {
                $currentAuthor = [];
                $uri = $current->xpath('@valueURI');
                if (isset($uri[0])) {
                    $currentAuthor['uri'] = (string)$uri[0];
                }
                $currentAuthor['name'] = implode(', ', $current->xpath('mods:namePart'));
                if (!empty($currentAuthor['name'])) {
                    $authors[] = $currentAuthor;
                }
            }
        }
        return $authors;
    }

    protected function extractExtent($mods)
    {
        $chapter = $mods->xpath('mods:part/mods:detail[@type="chapter"]/mods:number');
        $chapter = isset($chapter[0]) ? (string)$chapter[0] : '';
        $pageStart = $mods->xpath('mods:part/mods:extent[@unit="pages"]/mods:start');
        $pageStart = isset($pageStart[0]) ? (string)$pageStart[0] : '';
        $pageEnd = $mods->xpath('mods:part/mods:extent[@unit="pages"]/mods:end');
        $pageEnd = isset($pageEnd[0]) ? (string)$pageEnd[0] : $pageStart;
        $pageRange = ($pageStart === $pageEnd)
            ? (empty($pageStart) ? '' : 'page ' . $pageStart)
            : (empty($pageEnd) ? '' : "pages $pageStart-$pageEnd");
        $parts = [];
        if (!empty($chapter)) {
            $parts[] = (strstr($chapter, '-') ? 'chapters ' : 'chapter ') . $chapter;
        }
        if (!empty($pageRange)) {
            $parts[] = $pageRange;
        }
        return implode(', ', $parts);
    }

    protected function extractSubjects($mods)
    {
        $results = [];
        $paths = [
            'mods:genre', 'mods:topic', 'mods:geographic', 'mods:temporal',
            'mods:subject/mods:name' // don't grab just any name
        ];
        $prefixedPaths = [];
        foreach ($paths as $path) {
            $matches = $mods->xpath($path . '|mods:subject/' . $path);
            foreach ($matches as $current) {
                $uri = $current->xpath('@valueURI');
                $value = (string)$current;
                if (empty($value)) {
                    
                }
                $value = trim($value);
                if (empty($value)) {
                    $parts = $current->xpath('mods:namePart');
                    foreach ($parts as $namePart) {
                        $value .= (string)$namePart . ' ';
                    }
                }
                if (isset($uri[0])) {
                    $results[(string)$uri[0]] = trim($value);
                }
            }
        }
        return $results;
    }

    protected function assembleTitle($current, $includeSubtitle = true)
    {
        $title = trim((string)$current->xpath('mods:title')[0]);
        if ($includeSubtitle) {
            $subTitleParts = $current->xpath('mods:subTitle');
            $subtitle = isset($subTitleParts[0])
                ? trim((string)$subTitleParts[0]) : '';
            if (!empty($subtitle)) {
                $title .= ' : ' . $subtitle;
            }
        }
        $article = $current->xpath('mods:nonSort');
        return $title . (empty($article) ? '' : ', ' . trim((string)$article[0]));
    }

    protected function extractTitleInfo($mods, $includeSubtitle = false)
    {
        $matches = $mods->xpath('mods:titleInfo[not(@type="alternative")]');
        if (empty($matches)) {
            return '';
        }
        return $this->assembleTitle($matches[0], $includeSubtitle);
    }

    protected function extractAltTitleInfo($mods)
    {
        $matches = $mods->xpath('mods:titleInfo[@type="alternative"]');
        $results = array_map([$this, 'assembleTitle'], $matches);
        $mainTitleNoSub = $this->extractTitleInfo($mods, false);
        $mainTitleWithSub = $this->extractTitleInfo($mods, true);
        if ($mainTitleNoSub != $mainTitleWithSub) {
            $results[] = $mainTitleWithSub;
        }
        return $results;
    }
}
