<?php
/**
 * Edit tag controller
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
namespace GeebyDeeby\Controller;

/**
 * Edit tag controller
 *
 * @category GeebyDeeby
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class EditTagController extends AbstractBase
{
    /**
     * Display a list of tags
     *
     * @return mixed
     */
    public function listAction()
    {
        $view = $this->getGenericList(
            'tag', 'tags', 'geeby-deeby/edit-tag/render-tags'
        );
        // If this is not an AJAX request, we also want to display types:
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $view->tagTypes = $this->typelistAction()->tagTypes;
        }
        return $view;
    }

    /**
     * Operate on a single tag
     *
     * @return mixed
     */
    public function indexAction()
    {
        $assignMap = array(
            'tag' => 'Tag',
            'type_id' => 'Tag_Type_ID'
        );
        $view = $this->handleGenericItem('tag', $assignMap, 'tag');
        $view->tagTypes = $this->typelistAction()->tagTypes;
        // Add extra fields/controls if outside of a lightbox:
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $view->uris = $this->getDbTable('tagsuris')
                ->getURIsForTag($view->tagObj->Tag_ID);
            $view->setTemplate('geeby-deeby/edit-tag/edit-full');
            $view->predicates = $this->getDbTable('predicate')->getList();
        }
        return $view;
    }

    /**
     * Display a list of types
     *
     * @return mixed
     */
    public function typelistAction()
    {
        return $this->getGenericList(
            'tagType', 'tagTypes', 'geeby-deeby/edit-tag/render-types'
        );
    }

    /**
     * Operate on a single type
     *
     * @return mixed
     */
    public function typeAction()
    {
        $assignMap = array('tagType' => 'Tag_Type');
        return $this->handleGenericItem('tagType', $assignMap, 'tagType');
    }

    /**
     * Deal with URIs
     *
     * @return mixed
     */
    public function uriAction()
    {
        $extras = ($pid = $this->params()->fromPost('predicate_id'))
            ? ['Predicate_ID' => $pid] : [];
        return $this->handleGenericLink(
            'tagsuris', 'Tag_ID', 'URI',
            'uris', 'getURIsForTag',
            'geeby-deeby/edit-tag/uri-list.phtml',
            $extras
        );
    }
}
