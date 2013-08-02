<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik\API;

use Exception;
use Piwik\DataTable\Row;
use Piwik\Period\Range;
use Piwik\DataTable;
use Piwik\Plugins\API\API;
use Piwik\API\Proxy;
use Piwik\API\ResponseBuilder;

/**
 * Base class for manipulating data tables.
 * It provides generic mechanisms like iteration and loading subtables.
 *
 * The manipulators are used in ResponseBuilder and are triggered by
 * API parameters. They are not filters because they don't work on the pre-
 * fetched nested data tables. Instead, they load subtables using this base
 * class. This way, they can only load the tables they really need instead
 * of using expanded=1. Another difference between manipulators and filters
 * is that filters keep the overall structure of the table intact while
 * manipulators can change the entire thing.
 *
 * @package Piwik
 * @subpackage Piwik_API
 */
abstract class DataTableManipulator
{
    protected $apiModule;
    protected $apiMethod;
    protected $request;

    private $apiMethodForSubtable;

    /**
     * Constructor
     *
     * @param bool $apiModule
     * @param bool $apiMethod
     * @param array $request
     */
    public function __construct($apiModule = false, $apiMethod = false, $request = array())
    {
        $this->apiModule = $apiModule;
        $this->apiMethod = $apiMethod;
        $this->request = $request;
    }

    /**
     * This method can be used by subclasses to iterate over data tables that might be
     * data table arrays. It calls back the template method self::doManipulate for each table.
     * This way, data table arrays can be handled in a transparent fashion.
     *
     * @param DataTable\Map|DataTable $dataTable
     * @throws Exception
     * @return DataTable\Map|DataTable
     */
    protected function manipulate($dataTable)
    {
        if ($dataTable instanceof DataTable\Map) {
            return $this->manipulateDataTableArray($dataTable);
        } else if ($dataTable instanceof DataTable) {
            return $this->manipulateDataTable($dataTable);
        } else {
            return $dataTable;
        }
    }

    /**
     * Manipulates child DataTables of a DataTable_Array. See @manipulate for more info.
     */
    protected function manipulateDataTableArray($dataTable)
    {
        $result = $dataTable->getEmptyClone();
        foreach ($dataTable->getArray() as $tableLabel => $childTable) {
            $newTable = $this->manipulate($childTable);
            $result->addTable($newTable, $tableLabel);
        }
        return $result;
    }

    /**
     * Manipulates a single DataTable instance. Derived classes must define
     * this function.
     */
    protected abstract function manipulateDataTable($dataTable);

    /**
     * Load the subtable for a row.
     * Returns null if none is found.
     *
     * @param DataTable $dataTable
     * @param Row $row
     *
     * @return DataTable
     */
    protected function loadSubtable($dataTable, $row)
    {
        if (!($this->apiModule && $this->apiMethod && count($this->request))) {
            return null;
        }

        $request = $this->request;

        $idSubTable = $row->getIdSubDataTable();
        if ($idSubTable === null) {
            return null;
        }

        $request['idSubtable'] = $idSubTable;
        if ($dataTable) {
            $period = $dataTable->metadata['period'];
            if ($period instanceof Range) {
                $request['date'] = $period->getDateStart() . ',' . $period->getDateEnd();
            } else {
                $request['date'] = $period->getDateStart()->toString();
            }
        }

        $class = Request::getClassNameAPI( $this->apiModule );
        $method = $this->getApiMethodForSubtable();

        $this->manipulateSubtableRequest($request);
        $request['serialize'] = 0;
        $request['expanded'] = 0;

        // don't want to run recursive filters on the subtables as they are loaded,
        // otherwise the result will be empty in places (or everywhere). instead we
        // run it on the flattened table.
        unset($request['filter_pattern_recursive']);

        $dataTable = Proxy::getInstance()->call($class, $method, $request);
        $response = new ResponseBuilder($format = 'original', $request);
        $dataTable = $response->getResponse($dataTable);
        if (method_exists($dataTable, 'applyQueuedFilters')) {
            $dataTable->applyQueuedFilters();
        }

        return $dataTable;
    }

    /**
     * In this method, subclasses can clean up the request array for loading subtables
     * in order to make ResponseBuilder behave correctly (e.g. not trigger the
     * manipulator again).
     *
     * @param $request
     * @return
     */
    protected abstract function manipulateSubtableRequest(&$request);

    /**
     * Extract the API method for loading subtables from the meta data
     *
     * @return string
     */
    private function getApiMethodForSubtable()
    {
        if (!$this->apiMethodForSubtable) {
            $meta = API::getInstance()->getMetadata('all', $this->apiModule, $this->apiMethod);
            if (isset($meta[0]['actionToLoadSubTables'])) {
                $this->apiMethodForSubtable = $meta[0]['actionToLoadSubTables'];
            } else {
                $this->apiMethodForSubtable = $this->apiMethod;
            }
        }
        return $this->apiMethodForSubtable;
    }
}
