<?php
namespace exface\EpsonIHubPrinterConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\Exceptions\ErrorExceptionInterface;
use exface\Core\Widgets\Data;
use exface\UrlDataConnector\Psr7DataQuery;
use kabachello\phpTextTable\TextTable;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\DataSourceFactory;

class PrintData extends AbstractAction
{

    private $document_object_relation_path = null;

    private $document_id_attribute_alias = null;

    private $data_widget = null;

    private $header_text = null;

    private $footer_text = null;

    private $footer_barcode = null;

    private $footer_barcode_attribute_alias = null;

    private $footer_barcode_type = 'code39';

    private $data_connection_alias = null;
    
    private $dataSource = null;

    private $print_to_spool = null;

    private $print_template = null;

    private $device_id = null;

    private $printer_name = null;

    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setIcon(Icons::PRINT_);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        try {
            $message = $this->performPrint($this->getInputDataSheet($task));
        } catch (\Throwable $ex) {
            $message = $ex->getMessage();
            throw new ActionRuntimeError($this, 'Printing failed!', null, $ex);
        }
        return ResultFactory::createMessageResult($task, $message);
    }

    private function asTranslated($message)
    {
        return $this->getWorkbench()->getApp('exface.EpsonIHubPrinterConnector')->getTranslator()->translate($message);
    }

    protected function performPrint(DataSheetInterface $dataSheet)
    {
        $this->accept($dataSheet);
        
        $documentObject = $this->getDocumentObject();
        
        // print main object or print children
        $document_data = $this->prepareDataSheet($documentObject);
        if ($this->getDocumentObjectRelationPath()) { // print dependent objects
            $rev_path = $this->getMetaObject()->getRelation($this->getDocumentObjectRelationPath())->getReversedRelation()->getAlias();
            $document_data->addFilterFromString($rev_path, implode($dataSheet->getUidColumn()->getAttribute()->getValueListDelimiter(), array_unique($dataSheet->getUidColumn()->getValues(false))));
        } else { // print main objects
            $uuidList = $dataSheet->getUidColumn()->getValues(false);
            $document_data->addFilterFromString($documentObject->getUidAttributeAlias(), implode($documentObject->getUidAttribute()->getValueListDelimiter(), array_unique($uuidList)));
        }
        
        $document_data->dataRead();
        
        if ($this->getFooterBarcodeAttributeAlias() && $document_data->getColumns()->getByExpression($this->getFooterBarcodeAttributeAlias())) {
            $this->setFooterBarcode($document_data->getCellValue($this->getFooterBarcodeAttributeAlias(), 0));
        }
        
        // Print header
        $xml = $this->buildPrinterXmlLogo() . '<text>' . $this->getHeaderText() . "\n" . '</text>' . "\n";
        
        // Print content
        if ($this->isPrintDataDefinedAsTemplate()) {
            $xml .= $this->buildPrinterXmlByTemplate($document_data);
        } elseif ($this->isPrintDataDefinedAsColumns()) {
            $xml .= $this->buildPrinterXmlByColumns($document_data);
        } else {
            throw new ActionRuntimeError($this, $this->asTranslated('MISSING_DEFINITION_RECEIPT_PRINTING'));
        }
        
        $xml .= $this->buildXmlFooter();
        
        // direct print or spooling
        if ($this->getPrintToSpool()) {
            $this->sendToSpool($xml);
            $message = $this->asTranslated("RECEIPT_PRINTING_DOCUMENT_SENT_TO_SPOOL");
        } else {
            try {
                $this->sendToPrinter($xml);
                $message = $this->asTranslated("RECEIPT_PRINTING_DOCUMENT_SENT");
            } catch (ErrorExceptionInterface $e) {
                $message = $this->asTranslated("RECEIPT_PRINTING_FAILED");
            }
        }
        
        return $message;
    }

    protected function accept()
    {
        $rows = $this->getInputDataSheet()->getRows();
        if (empty($rows)) {
            throw new ActionRuntimeError($this, $this->asTranslated('PLEASE_SELECT_ATLEAST_ONE_ROW'));
        }
    }

    public function getPrintToSpool()
    {
        if (is_null($this->print_to_spool)) {
            $this->print_to_spool = $this->getPrinterConfig()->getOption('DEFAULT_USE_PRINT_SPOOL') ? true : false;
        }
        return $this->print_to_spool;
    }

    public function setPrintToSpool($value)
    {
        $this->print_to_spool = \exface\Core\DataTypes\BooleanDataType::cast($value);
        return $this;
    }

    protected function getDocumentObject()
    {
        if ($this->getDocumentObjectRelationPath()) {
            $document_object = $this->getMetaObject()->getRelatedObject($this->getDocumentObjectRelationPath());
        } else {
            $document_object = $this->getMetaObject(); // ->getMetaObject();
        }
        return $document_object;
    }

    protected function buildXmlCut()
    {
        return '<cut/>';
    }

    protected function getPrinterXML($xmlPosPrint)
    {
        return $xmlPosPrint;
    }

    /**
     *
     * @param string $xml            
     * @return Psr7DataQuery
     */
    protected function sendToPrinter($xml)
    {
        $xml = <<<XML
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
	<s:Header>
		<parameter xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
			<devid>{$this->getPrinterConfig()->getOption('DEFAULT_PRINTER_DEVICE_ID')}</devid>
			<timeout>{$this->getPrinterConfig()->getOption('DEFAULT_PRINTING_TIMEOUT')}</timeout>
			<printjobid>{$this->getPrinterConfig()->getOption('DEFAULT_PRINT_JOB_ID')}</printjobid>
		</parameter>
	</s:Header>
	<s:Body>
		<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
			{$xml}
		</epos-print>
	</s:Body>
</s:Envelope>
XML;
        $query = Psr7DataQuery::createRequest('POST', $this->getPrinterConfig()->getOption('WEBSERVICE_URL'), array(), $xml);
        return $this->getDataConnection()->query($query);
    }

    public function setColumns($value)
    {
        $this->getDataWidget()->setColumns($value);
        return $this;
    }

    public function getHeaderText()
    {
        return $this->header_text;
    }

    public function isPrintDataDefinedAsTemplate()
    {
        return ! empty($this->getPrintTemplate());
    }

    public function setHeaderText($value)
    {
        $this->header_text = $value;
        return $this;
    }

    public function getPrintTemplate()
    {
        return $this->print_template;
    }

    public function setPrintTemplate($print_template)
    {
        $this->print_template = $print_template;
    }

    public function getPrinterConfig()
    {
        return $this->getWorkbench()->getApp('exface.EpsonIHubPrinterConnector')->getConfig();
    }

    public function getPrinterName()
    {
        if (is_null($this->printer_name)) {
            $this->printer_name = $this->getPrinterConfig()->getOption('DEFAULT_PRINTER_NAME');
        }
        return $this->printer_name;
    }

    /**
     * Sets the name of the iHub unit to use in this action.
     * This is what is used in DirectPrint URLs to distinguish printers.
     *
     * Every active printer in the network should have a unique name to be able to fetch it's print jobs from the print server.
     * This option is only important for printers operating via DirectPrint (pulling print jobs from the server).
     *
     * @uxon-property printer_name
     * @uxon-type string
     *
     * @param string $value            
     * @return \exface\EpsonIHubPrinterConnector\Actions\PrintData
     */
    public function setPrinterName($value)
    {
        $this->printer_name = $value;
        return $this;
    }

    public function getDeviceId()
    {
        if (empty($this->device_id)) {
            return $this->getPrinterConfig()->getOption('DEFAULT_PRINTER_DEVICE_ID');
        }
        return $this->device_id;
    }

    /**
     * Sets the device_id used to address external printers by the iHub.
     * It's local_printer (= the iHub itself) by default.
     *
     * Some iHub units may have external printers connected, that can be addressed via their device id. While the "printer_name"
     * property defines, which iHub unit the print job is for, the "device_id" tells this unit, which printer to use. The iHub
     * printer itself has the built-in device id "local_printer" (used by default). Device ids of external printers can be
     * found in the webconfig of the iHub unit, they are connected to.
     *
     * @uxon-property device_id
     * @uxon-type string
     *
     * @param string $value            
     * @return \exface\EpsonIHubPrinterConnector\Actions\PrintData
     */
    public function setDeviceId($device_id)
    {
        $this->device_id = $device_id;
    }

    public function getDocumentIdAttributeAlias()
    {
        return $this->document_id_attribute_alias;
    }

    public function setDocumentIdAttributeAlias($value)
    {
        $this->document_id_attribute_alias = $value;
        return $this;
    }

    public function getFooterText()
    {
        return $this->footer_text;
    }

    public function setFooterText($value)
    {
        $this->footer_text = $value;
        return $this;
    }

    public function getFooterBarcode()
    {
        return $this->footer_barcode;
    }

    public function setFooterBarcode($value)
    {
        $this->footer_barcode = $value;
        return $this;
    }

    public function getDocumentObjectRelationPath()
    {
        return $this->document_object_relation_path;
    }

    public function setDocumentObjectRelationPath($value)
    {
        $this->document_object_relation_path = $value;
        return $this;
    }

    /**
     *
     * @return Data
     */
    protected function getDataWidget()
    {
        if (is_null($this->data_widget)) {
            $page = $this->getWidgetDefinedIn()->getPage();
            $this->data_widget = WidgetFactory::create($page, 'Data', $this->getWidgetDefinedIn());
            $this->data_widget->setMetaObject($this->getDocumentObject());
        }
        return $this->data_widget;
    }

    /**
     *
     * @return string
     */
    public function getDataConnectionAlias()
    {
        return $this->data_connection_alias;
    }

    /**
     *
     * @param string $value            
     * @return \exface\EpsonIHubPrinterConnector\Actions\PrintData
     */
    public function setDataConnectionAlias($value)
    {
        $this->data_connection_alias = $value;
        return $this;
    }

    /**
     *
     * @return DataConnectionInterface
     */
    protected function getDataConnection()
    {
        if ($this->dataSource === null) {
            $this->dataSource = DataSourceFactory::createFromModel($this->getWorkbench(), $this->getPrinterConfig()->getOption('DATA_SOURCE_UID'), $this->getDataConnectionAlias());
        }
        return $this->dataSource->getConnection();
    }

    /**
     *
     * @param
     *            $documentObject
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function prepareDataSheet($documentObject)
    {
        $document_data = $this->getDataWidget()->prepareDataSheetToRead(DataSheetFactory::createFromObject($documentObject));
        
        $columns = $this->getDataWidget()->getColumns(); // Are columns defined?
        if (empty($columns)) {
            // add all columns from object
            foreach ($this->getDocumentObject()->getAttributes() as $attr) {
                $document_data->getColumns()->addFromAttribute($attr);
            }
        }
        return $document_data;
    }

    protected function isPrintDataDefinedAsColumns()
    {
        $dataWidgetColumns = $this->getDataWidget()->getColumns();
        return ! empty($dataWidgetColumns);
    }

    /**
     *
     * @param $document_data \exface\Core\CommonLogic\DataSheets\DataSheet            
     */
    protected function buildPrinterXmlByTemplate($document_data)
    {
        $xmlPosPrint = "";
        
        $template = $this->getPrintTemplate();
        
        foreach ($document_data->getRows() as $row) {
            $tmp = $template;
            foreach ($row as $key => $value) {
                $tmp = str_replace("[#" . strtoupper($key) . "#]", $value, $tmp);
            }
            // TODO replace missing tags (regex)
            $xmlPosPrint .= $tmp;
        }
        
        return $xmlPosPrint;
    }

    protected function buildPrinterXmlLogo()
    {
        return <<<XML
    	
<text align="center"/>
<image width="256" height="233" color="color_1" mode="mono">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AgAAAAAEiJAEAAEglJCUkWIkkkBJGQQSICAgAAAAgAIAAAAEAAIgAAAAAkVJJkkkDIkomyRAAAAAUAAAIAIAACAAAAAABJYAAEAAsiJIokqhUkUgSSgAAAAAACAAAACIAACAIAAJSAAABAEElJIUkBQkkkqSkAgAAKAAQAIIAAAAAAEAAIIigCAAQFJJJMElSokokSRAAAAAQCAAAAAAAAACBAAECJRAAAAAKSJJGkghUkUkSSAAIBGQgABAAAIAAEAAACAVSQBAAAAElJJEkpQkkIqSgCAAAiABAAAgAAAhAAAAAAIisQCAACkhJJEkSokmUSRAAAJAgAAAAAAAAAAEAABAFJRAAAACAkxJKkkhUkiESQBAAAUgAAAAAAgIgAAABAAJSRQABAAMkZJAkkwEkjKSQQAAAkAAAQIgIAAAAAgAADISogAAQAEkJBYkEWkkySSAAEAIkAAAAAAAAAAAACAABKQIAAAEAklJSUmkhEkESQAABBUgQAgIAAAgAQAAQABpEWaBCAAAkpIiIhIpElKSAAAAAkACQAAAgAAAACAAAAJKIAAAABEkJJSUZJKkiSQCAIA0kAAAAAAAAAAIAAAgqKTKwAAAAElJSUEJREkkSAAAAEEgAAAAAAAAiACAAAJFEREkAAAAEhIiLKIpElKSAAIAFEiAACAgAEAAAACAABpKREggAAAkxJSCFJKkiSQACADKkAQAAEECAgAAAABAoJCakABIIAkRIlhJRAkkSAAAAREgAACBAAAAAEAAAABKJiEkAACAEkpJIiIpYkkgCAACREgAAgAACAAABAECARFIykkAAABElJSJlIKUkqAAQACakAAgAAAAAAAAAAAAxJIgkgCAAQkhIlJJWCIkSAAABSBIAAAAAAAABAAEAAMSJKUkgAAAAkpJBCIFSUkgAAACSySIQAAAAAQAACAEAE1JSkkiAAAElJJpSLKUEkAgAAiQkAAAAgAAICBAAAAFIJIgkkAAAAEhJISWRCGkoACCJSUkAAAAAJAAAAQAACCVJKUkkAEAAkpJKSERShEAAAAIikgAAAAgAIAAAAAAAkhJCEkgABEEkJJESkogpEAgABJQkgAACAAAAAAAACAFIxJjEkwEAAEmJJIgpMZKgAAAJSUkAIEgAAAAAIAgAACURJSkkAAAAklJKaURKREAAARIiEkSCAAAgAACAAEASkkxCREkAAAEkJJEEkpEpAAAgBJTEgAAACAAAEgAAAABIkZiSkggAAEmJJJIpJESgIAApISkAAAAAAEIAAIAAQqUkIikkAAABEiJJCURKkkAAABJMQkAAAAAAAAAAAAgAEElJhEgABESkVJJkkpApAICARJGYgAAAAEAAAAAIgALLEpIykABAAEkJJJIoJYgAAAEpJCMAAAAQAQAgAAAAARCkJIggAAABEqJJCUNIUoAAAEJJREAAQgAACAABAAACRRFJKUAAAAKkVJJklCMoAAEBGJKRAQIAAAAAAAAAAASaSpJSkAgAAEkJJIkhSEgAAAJlJCpAEAAAggABCAAIISCkJIggAAAGklJJEkyVEAAAAIJJRIAAABAAAAAQAAAEShFJKUAgRIgkpJJEkiKqAIgFKJIRIAACAAAIIAAJEAKRSpJCAAAAAskJJKkhSEAAAAJFJMpAAAAACAAAAAAACSSkJJkAQAAUElJJBEyVFAIACJJJEIAACAAAAAAAAAAEShFJIgAAAAGkhJIZESKoEABFKJJGQQAgAAAAQQAAACkRShJIAAAALAkxJILESEAAABJFJJkAEIAQkBAAABAABMShZJAAAIASokpJKBKVEAAACJJJIKAAAAAAAAAIAAApGRSJIAAABMRUhJJFSSCgAAkTKJJKQAAAAAAAAAAAEQRCQiJCAAAAEQkxJJikSxAAACRFJJESAAAAAACAAEBAGZSZSJAAgAFKokRJAxKQQAAACRJJJKABACAAQAIgAAACKSSVIAgJAiRUkpIoSSaoCJASSJJKSAAAAQAAAAABAAiCSSJAAAAEkQklJIUkSBAAAASlJJEQEAAAEQIAAAAABTSSSIAAAAkqokhJGJKSwAAAGRJJBKAAAQAAAAgAgAACQiSVAAAAEkgUkpJCSSQQIAACSJJSEAAIAAAAAAACAEAUiSAAEAAEkWIkRKkkSUgAABSlJIlgAAAAAAAAAAAAAAliSgAAQFEqCUkpBJKSpAAAQRJJJAiAgAkACBAIAAEAEgiQAAAElkFUkpJaSCQQAIAMSJJS0AIAAAAAAACICAAEpiAAAAAglKIkRIEliUoACQGSJIkACAAACRAAIAAAAhkRQAAAgUoiCUkpKkhSJCIAAiVJJFAAACAAACAAAAAAAkokACAElUlUkkJEkoiQAAAEiJJJIAAEgAAAAAAAEAAEpIA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</image>
<feed line="1"/>
<text>www.alexa-retail.com\n</text>
<feed line="2"/>
<text align="left"/>
XML;
    }

    /**
     *
     * @param
     *            $document_data
     * @return string
     */
    protected function buildPrinterXmlByColumns(DataSheetInterface $document_data)
    {
        $rows = array();
        $column_widths = array();
        $column_alignments = array();
        foreach ($this->getDataWidget()->getColumns() as $col) {
            if ($col->isHidden())
                continue;
            foreach ($document_data->getColumns()->get($col->getDataColumnName())->getValues(true) as $row => $value) {
                $rows[$row][$col->getCaption()] = $value;
            }
            if ($col->getWidth()) {
                $column_widths[$col->getCaption()] = $col->getWidth()->getValue();
            }
            if ($col->getAlign()) {
                $column_alignments[$col->getCaption()] = $col->getAlign();
            }
        }
        
        $text_table = new TextTable($rows);
        $text_table->setColumnAlignments($column_alignments);
        $text_table->setColumnWidthMax($column_widths);
        $text_table->setColumnWidthAuto(false);
        $text_table->setPrintHeader(false);
        $text_table->setSeparatorColumn('');
        $text_table->setSeparatorCrossing('');
        $text_table->setSeparatorRow('');
        
        foreach (array_keys($rows) as $row) {
            $xml .= '<text>' . $text_table->print($row) . '</text>' . "\n";
        }
        
        return $xml;
    }

    protected function buildXmlFooter()
    {
        $xml = '';
        if ($this->getFooterBarcode()) {
            $xml .= '<barcode type="' . $this->getFooterBarcodeType() . '" hri="none" font="font_a" width="2" height="32">' . "\n" . $this->getFooterBarcode() . '</barcode>' . "\n";
        }
        if ($this->getFooterText()) {
            $xml .= '<feed line="1"/><text align="center"/><text>' . "\n" . $this->getFooterText() . "\n" . '</text>' . "\n";
        }
        $xml .= $this->buildXmlCut();
        return $xml;
    }

    /**
     *
     * @param
     *            $xml
     */
    protected function sendToSpool($xml)
    {
        $pool_ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.EpsonIHubPrinterConnector.PRINT_JOB');
        $pool_ds->getColumns()->addFromExpression('printer_name');
        $pool_ds->getColumns()->addFromExpression('device_id');
        $pool_ds->getColumns()->addFromExpression('content');
        $pool_ds->getColumns()->addFromExpression('state_id');
        
        $pool_ds->addRow(array(
            'printer_name' => $this->getPrinterName(),
            'device_id' => $this->getDeviceId(),
            "content" => $this->getPrinterXML($xml),
            "state_id" => PrintSpoolData::STATE_PRINT_JOB_CREATED
        ));
        
        $pool_ds->dataCreate(false);
    }

    public function getFooterBarcodeAttributeAlias()
    {
        return $this->footer_barcode_attribute_alias;
    }

    public function setFooterBarcodeAttributeAlias($value)
    {
        $this->footer_barcode_attribute_alias = $value;
        return $this;
    }

    public function getFooterBarcodeType()
    {
        return $this->footer_barcode_type;
    }

    public function setFooterBarcodeType($value)
    {
        $this->footer_barcode_type = $value;
        return $this;
    }
}
?>