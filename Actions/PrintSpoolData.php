<?php namespace exface\EpsonIHubPrinterConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Factories\DataSheetFactory;

class PrintSpoolData extends AbstractAction
{
    const STATE_PRINT_JOB_CREATED = 10;
    const STATE_PRINT_JOB_PRINTED = 99;

    private $printer = null;
    private $debug = false;

    protected function perform()
    {
        try {
            $printerResponse = $this->performPrint();
            echo $printerResponse;
        } catch (\Throwable $ex) {
            $this->set_result_message($ex->getMessage());
            // Since we are talking to a printer here, there is no need to do any standard exception handling - it would
            // only irretate the printer. For debug purposes we can always add the &debug=1 URL parameter.
            if ($this->isInDebugMode()){
            	throw $ex;
            } else {
            	// TODO Output some XML that will let the printer know, something went wrong.
            }
        }
    }
    
    protected function isInDebugMode(){
    	return $this->get_workbench()->get_request_param('debug') ? true : false;
    }

    protected function performPrint()
    {
        $this->setPrinter();

        $document_data = $this->preparePrintJobDataSheet();
        $document_data->add_filter_from_string("state_id", self::STATE_PRINT_JOB_CREATED);
        $document_data->add_filter_from_string("device_id", $this->printerId);

        $document_data->data_read();

        list($xmlPosPrint, $printedJobs) = $this->buildXmlPosPrint($document_data);

        $document_data->remove_rows();
        $document_data->add_rows($printedJobs);

        if(!empty($xmlPosPrint)) {
            $document_data->data_update(); //mark records as printed
            return $this->getPrinterXML($xmlPosPrint);
        }
        return "";
    }

    protected function getPrinterXML($xmlPosPrints) {

        $xml = sprintf('<?xml version="1.0" encoding="UTF-8"?>
                <PrintRequestInfo>
                   <ePOSPrint>
                      <Parameter>
                         <devid>%s</devid>
                         <timeout>%s</timeout>
                      </Parameter>
                      <PrintData>
                         <epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
                            %s
                         </epos-print>
                      </PrintData>
                   </ePOSPrint>
                </PrintRequestInfo>'
            , $this->printerId
            , $this->get_app()->get_config()->get_option('DEFAULT_PRINTING_TIMEOUT')
            , $xmlPosPrints);
        return $xml;
    }

    protected function setPrinter() {
        if( isset($_REQUEST["printer_id"])) {
            $this->printerId = trim($_REQUEST["printer_id"]);
        }
        else {
            $this->printerId = $this->get_app()->get_config()->get_option('DEFAULT_PRINTER_DEVICE_ID');
        }
    }

    /**
     * @return string
     */
    protected function getPrintJobAlias()
    {
        return "exface.EpsonIHubPrinterConnector.PRINT_JOB";
    }

    /**
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function preparePrintJobDataSheet()
    {
        /** @var \exface\Core\CommonLogic\Model\Object $printJobMetaObject */
        $printJobMetaObject = $this->get_workbench()->model()->get_object($this->getPrintJobAlias());

        $document_data = DataSheetFactory::create_from_object_id_or_alias($this->get_workbench(), $printJobMetaObject);
        foreach ($printJobMetaObject->get_attributes() as $attr) {
            $document_data->get_columns()->add_from_attribute($attr);
        }
        return $document_data;
    }

    /**
     * @param $document_data
     * @return array
     */
    protected function buildXmlPosPrint($document_data)
    {
        $xmlPosPrint = "";
        $printedJobs = array();
        foreach ($document_data->get_rows() as $row) {
            $xmlPosPrint .= $row["content"];

            $row["state_id"] = self::STATE_PRINT_JOB_PRINTED;
            $printedJobs[] = $row;
        }
        return array($xmlPosPrint, $printedJobs);
    }
}
?>