<?php
/**
 * Publisher controller
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
namespace GeebyDeeby\Controller;

/**
 * Publisher controller
 *
 * @category GeebyDeeby
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class PublisherController extends AbstractBase
{
    /**
     * 303 redirect page
     *
     * @return mixed
     */
    public function indexAction()
    {
        return $this->performRdfRedirect('publisher');
    }

    /**
     * RDF representation page
     *
     * @return mixed
     */
    public function rdfAction()
    {
        $view = $this->getPublisherViewModel();
        if (!is_object($view)) {
            $response = $this->getResponse();
            $response->setStatusCode(404);
            return $response;
        }

        $graph = new \EasyRdf\Graph();
        $uri = $this->getServerUrl('publisher', ['id' => $view->publisher['Publisher_ID']]);
        $pub = $graph->resource($uri, 'foaf:CorporateBody');
        $pub->set('rda:P60549', $view->publisher['Publisher_Name']);

        return $this->getRdfResponse($graph);
     }

    /**
     * "Show publisher" page
     *
     * @return mixed
     */
    public function showAction()
    {
        $view = $this->getPublisherViewModel();
        if (!$view) {
            return $this->forwardTo(__NAMESPACE__ . '\Publisher', 'notfound');
        }
        return $view;
    }

    /**
     * Get view model for publisher (or return false if not found).
     *
     * @return mixed
     */
    protected function getPublisherViewModel()
    {
        $id = $this->params()->fromRoute('id');
        $table = $this->getDbTable('publisher');
        $rowObj = (null === $id) ? null : $table->getByPrimaryKey($id);
        if (!is_object($rowObj)) {
            return false;
        }
        $view = $this->createViewModel(
            array('publisher' => $rowObj->toArray())
        );
        $view->series = $this->getDbTable('seriespublishers')
            ->getSeriesForPublisher($id);
        return $view;
    }

    /**
     * Publisher list
     *
     * @return mixed
     */
    public function listAction()
    {
        return $this->createViewModel(
            array('publishers' => $this->getDbTable('publisher')->getList())
        );
    }

    /**
     * Not found page
     *
     * @return mixed
     */
    public function notfoundAction()
    {
        return $this->createViewModel();
    }
}
