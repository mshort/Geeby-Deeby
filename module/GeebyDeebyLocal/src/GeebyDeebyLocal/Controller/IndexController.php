<?php
/**
 * Index controller
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
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
namespace GeebyDeebyLocal\Controller;

/**
 * Search controller
 *
 * @category GeebyDeeby
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class IndexController extends \GeebyDeeby\Controller\IndexController
{
    public function indexAction()
    {
        return $this->createViewModel(
            array('episodes' => array(
                'mittie'    => $this->podcast()->getMetadata(4, 'Mittie\'s Storytime'),
                'professor' => $this->podcast()->getMetadata(4, 'Professor M\'s Lecture Series'),
            ))
        );
    }

    public function loginAction()
    {
        $result = parent::loginAction();
        if (is_callable([$result, 'setTemplate'])) {
            $result->setTemplate('geeby-deeby/index/login');
        }
        return $result;
    }
}