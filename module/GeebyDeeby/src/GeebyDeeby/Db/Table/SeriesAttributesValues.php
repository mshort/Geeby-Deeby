<?php
/**
 * Table Definition for Series_Attributes_Values
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
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category GeebyDeeby
 * @package  Db_Table
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
namespace GeebyDeeby\Db\Table;

/**
 * Table Definition for Series_Attributes_Values
 *
 * @category GeebyDeeby
 * @package  Db_Table
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class SeriesAttributesValues extends Gateway
{
    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct('Series_Attributes_Values');
    }

    /**
     * Get a list of attributes for the specified series.
     *
     * @var int $seriesID Series ID
     *
     * @return mixed
     */
    public function getAttributesForSeries($seriesID)
    {
        $callback = function ($select) use ($seriesID) {
            $select->join(
                array('sa' => 'Series_Attributes'),
                'sa.Series_Attribute_ID = '
                . 'Series_Attributes_Values.Series_Attribute_ID'
            );
            $select->order(array('sa.Display_Priority', 'sa.Series_Attribute_Name'));
            $select->where->equalTo('Series_ID', $seriesID);
        };
        return $this->select($callback);
    }
}
