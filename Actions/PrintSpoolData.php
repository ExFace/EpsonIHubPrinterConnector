<?php namespace exface\EpsonIHubPrinterConnector\Actions;

use alexa\RMS\Core\AppUserException;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Factories\DataSheetFactory;

class PrintSpoolData extends AbstractAction
{
    const STATE_PRINT_JOB_CREATED = 10;
    const STATE_PRINT_JOB_PRINTED = 90;

    private $printer = null;

    protected function perform()
    {
        try {
            $printerResponse = $this->performPrint();
            echo $printerResponse;
        } catch (AppUserException $auex) {
            $message = $this->get_app()->get_translator()->translate($auex->getReadableMessage());
            $this->set_result_message($message);
            throw new \Exception($auex->getMessage());
        } catch (\Exception $ex) {
            $this->set_result_message($ex->getMessage());

            throw new \Exception($ex->getMessage());
        }
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