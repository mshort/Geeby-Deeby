<?php
/**
 * Person controller
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
 * Person controller
 *
 * @category GeebyDeeby
 * @package  Controller
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/demiankatz/Geeby-Deeby Main Site
 */
class PersonController extends AbstractBase
{
    /**
     * 303 redirect page
     *
     * @return mixed
     */
    public function indexAction()
    {
        return $this->performRdfRedirect('person');
    }

    /**
     * RDF representation page
     *
     * @return mixed
     */
    public function rdfAction()
    {
        $id = $this->params()->fromRoute('id');
        $view = $this->getPersonViewModel($id);
        if (!is_object($view)) {
            $response = $this->getResponse();
            $response->setStatusCode(404);
            return $response;
        }

        $graph = new \EasyRdf\Graph();
        $uri = $this->getServerUrl('person', ['id' => $id]);
        $person = $graph->resource($uri, 'foaf:Person');
        $name = $view->person['First_Name'] . ' ' . $view->person['Middle_Name']
            . ' ' . $view->person['Last_Name'];
        $person->set('foaf:name', trim(preg_replace('/\s+/', ' ', $name)));

        return $this->getRdfResponse($graph);
    }

    /**
     * "Show person" page
     *
     * @return mixed
     */
    public function showAction()
    {
        $id = $this->params()->fromRoute('id');
        if (null === $id) {
            return $this->forwardTo(__NAMESPACE__ . '\Person', 'list');
        }
        $view = $this->getPersonViewModel(
            $id, $this->params()->fromQuery('sort', 'series')
        );
        if (!is_object($view)) {
            return $this->forwardTo(__NAMESPACE__ . '\Person', 'notfound');
        }
        return $view;
    }

    /**
     * Person list
     *
     * @return mixed
     */
    public function listAction()
    {
        $extra = $this->params()->fromRoute('extra');
        $bios = (strtolower($extra) == 'bios');
        return $this->createViewModel(
            array(
                'bioMode' => $bios,
                'people' => $this->getDbTable('person')->getList($bios)
            )
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

    /**
     * Get the view model representing the specified person (or false if
     * invalid ID)
     *
     * @param int $id ID of person to load
     *
     * @return \Zend\View\Model\ViewModel|bool
     */
    protected function getPersonViewModel($id, $sort = 'title')
    {
        $table = $this->getDbTable('person');
        $rowObj = $table->getByPrimaryKey($id);
        if (!is_object($rowObj)) {
            return false;
        }
        $view = $this->createViewModel(
            array('person' => $rowObj->toArray())
        );
        $view->sort = $sort;
        $view->credits = $this->getDbTable('editionscredits')
            ->getCreditsForPerson($id, $view->sort);
        $pseudo = $this->getDbTable('pseudonyms');
        $view->pseudonyms = $pseudo->getPseudonyms($id);
        $view->realNames = $pseudo->getRealNames($id);
        $view->files = $this->getDbTable('peoplefiles')->getFilesForPerson($id);
        $view->bibliography = $this->getDbTable('peoplebibliography')
            ->getItemsDescribingPerson($id);
        $view->links = $this->getDbTable('peoplelinks')->getLinksForPerson($id);
        return $view;
    }
}
