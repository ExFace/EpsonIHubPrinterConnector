<?php

namespace exface\EpsonIHubPrinterConnector\Actions;

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
            $this->setResultMessage($ex->getMessage());
            // Since we are talking to a printer here, there is no need to do any standard exception handling - it would
            // only irretate the printer. For debug purposes we can always add the &debug=1 URL parameter.
            if ($this->isInDebugMode()) {
                throw $ex;
            } else {
                // TODO Output some XML that will let the printer know, something went wrong.
            }
        }
    }

    protected function isInDebugMode()
    {
        return $this->getWorkbench()->getRequestParam('debug') ? true : false;
    }

    protected function performPrint()
    {
        $this->setPrinter();
        
        $document_data = $this->preparePrintJobDataSheet();
        $document_data->addFilterFromString("state_id", self::STATE_PRINT_JOB_CREATED);
        $document_data->addFilterFromString("printer_name", $this->printerId);
        
        $document_data->dataRead();
        
        list ($xmlPosPrint, $printedJobs) = $this->buildXmlPosPrint($document_data);
        
        // FIXME add the device id to each job or split jobs by device id
        $deviceId = $document_data->getColumns()
            ->getByExpression('device_id')
            ->getCellValue(0);
        
        $document_data->removeRows();
        $document_data->addRows($printedJobs);
        
        if (! empty($xmlPosPrint)) {
            $document_data->dataUpdate(); // mark records as printed
            return $this->getPrinterXML($xmlPosPrint, $deviceId);
        }
        return "";
    }

    protected function getPrinterXML($xmlPosPrints, $deviceId)
    {
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
                </PrintRequestInfo>', $deviceId, $this->getApp()
            ->getConfig()
            ->getOption('DEFAULT_PRINTING_TIMEOUT'), $xmlPosPrints);
        return $xml;
    }

    protected function setPrinter()
    {
        if (isset($_REQUEST["printer"])) {
            $this->printerId = trim($_REQUEST["printer"]);
        } else {
            $this->printerId = $this->getWorkbench()
                ->getApp('exface.EpsonIHubPrinterConnector')
                ->getConfig()
                ->getOption('DEFAULT_PRINTER_NAME');
        }
    }

    /**
     *
     * @return string
     */
    protected function getPrintJobAlias()
    {
        return "exface.EpsonIHubPrinterConnector.PRINT_JOB";
    }

    /**
     *
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function preparePrintJobDataSheet()
    {
        /** @var \exface\Core\CommonLogic\Model\Object $printJobMetaObject */
        $printJobMetaObject = $this->getWorkbench()
            ->model()
            ->getObject($this->getPrintJobAlias());
        
        $document_data = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $printJobMetaObject);
        foreach ($printJobMetaObject->getAttributes() as $attr) {
            $document_data->getColumns()->addFromAttribute($attr);
        }
        return $document_data;
    }

    /**
     *
     * @param
     *            $document_data
     * @return array
     */
    protected function buildXmlPosPrint($document_data)
    {
        $xmlPosPrint = "";
        $printedJobs = array();
        foreach ($document_data->getRows() as $row) {
            $xmlPosPrint .= $row["content"];
            
            $row["state_id"] = self::STATE_PRINT_JOB_PRINTED;
            $printedJobs[] = $row;
        }
        return array(
            $xmlPosPrint,
            $printedJobs
        );
    }
}
?>