<?php namespace exface\EpsonIHubPrinterConnector\Actions;

use alexa\RMS\Core\AppUserException;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Interfaces\DataSources\DataConnectionInterface;
use exface\Core\Interfaces\Exceptions\ErrorExceptionInterface;
use exface\Core\Widgets\Data;
use exface\UrlDataConnector\Psr7DataQuery;
use kabachello\phpTextTable\TextTable;

class PrintData extends AbstractAction {

    private $document_object_relation_path = null;
	private $document_id_attribute_alias = null;
	private $data_widget = null;
	private $header_text = null;
	private $footer_text = null;
	private $footer_barcode = null;
	private $data_connection_alias = null;
	private $direct_print = true;
    private $print_template = null;
    private $device_id = null;

    protected function perform()
    {
        try {
            $this->performPrint();
        }
        catch(AppUserException $auex) {
            $message = $this->get_app()->get_translator()->translate($auex->getReadableMessage());
            $this->set_result_message($message);
            //throw new \Exception($auex->getMessage());
        }
        catch (\Exception $ex) {
            $this->set_result_message($ex->getMessage());

            throw new \Exception($ex->getMessage());
        }
    }

    private function asTranslated($message) {

        return $this->get_app()->get_translator()->translate($message);
    }

	protected function performPrint(){

	    $this->accept();

        $documentObject = $this->get_document_object();

        //print main object or print children
        $document_data = $this->prepareDataSheet($documentObject);
        if ($this->get_document_object_relation_path()) { //print dependent objects
            $rev_path = $this->get_meta_object()->get_relation($this->get_document_object_relation_path())->get_reversed_relation()->get_alias();
            $document_data->add_filter_from_string($rev_path, implode(EXF_LIST_SEPARATOR, array_unique($this->get_input_data_sheet()->get_uid_column()->get_values(false))));
        }
        else { //print main objects
            $uuidList = $this->get_input_data_sheet()->get_uid_column()->get_values(false);
            $document_data->add_filter_from_string($documentObject->get_uid_alias(), implode(EXF_LIST_SEPARATOR, array_unique($uuidList)));
        }

        $document_data->data_read();

        //print by columns or by template
        if( $this->isPrintDataDefinedAsColumns() ) {
            $xml = $this->buildPrinterXmlByColumns($document_data);
        }
        elseif ($this->isPrintDataDefinedAsTemplate()) {
            $xml = $this->buildPrinterXmlByTemplate($document_data);
        }
        else {
            AppUserException::triggerError("MISSING_DEFINITION_RECEIPT_PRINTING");
        }

        //direct print or spooling
		if(!$this->get_direct_print()){
            $this->sendToSpool($xml);
            $this->set_result_message($this->asTranslated("RECEIPT_PRINTING_DOCUMENT_SENT_TO_SPOOL"));
		} else {
			try {
				$this->send_to_printer($xml);
                $this->set_result_message($this->asTranslated("RECEIPT_PRINTING_DOCUMENT_SENT"));
			}
			catch (ErrorExceptionInterface $e){
				$this->set_result_message($this->asTranslated("RECEIPT_PRINTING_FAILED"));
			}
		}
	}

    protected function accept()
    {
        $rows = $this->get_input_data_sheet()->get_rows();
        if( empty($rows) ) {
            AppUserException::triggerError("PLEASE_SELECT_ATLEAST_ONE_ROW");
        }
    }
	
	public function get_direct_print() {
		return $this->direct_print;
	}
	
	public function set_direct_print($value) {
		$this->direct_print = $value ? true : false;
		return $this;
	}
	
	protected function get_document_object(){

	    if ($this->get_document_object_relation_path()){
			$document_object = $this->get_meta_object()->get_related_object($this->get_document_object_relation_path());
		} else {
			$document_object = $this->get_meta_object(); //->get_meta_object();
		}
		return $document_object;
	}
	
	protected function build_xml_cut(){
		return '<cut/>';
	}

	protected function getPrinterXML($xmlPosPrint) {

	    /* attached later by spool!
	     * $xml = sprintf('<?xml version="1.0" encoding="UTF-8"?>
                <PrintRequestInfo>
                   <ePOSPrint>
                      <Parameter>
                         <devid>%s</devid>
                         <timeout>10000</timeout>
                      </Parameter>
                      <PrintData>
                         <epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
                            %s
                         </epos-print>
                      </PrintData>
                   </ePOSPrint>
                </PrintRequestInfo>',  $this->get_device_id(), $xmlPosPrint);
	    return $xml;*/
        return $xmlPosPrint;
    }
	
	/**
	 * 
	 * @param string $xml
	 * @return Psr7DataQuery
	 */
	protected function send_to_printer($xml){
		//print($xml);
		$xml = <<<XML
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
	<s:Header>
		<parameter xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
			<devid>{$this->get_app()->get_config()->get_option('DEFAULT_PRINTER_DEVICE_ID')}</devid>
			<timeout>{$this->get_app()->get_config()->get_option('DEFAULT_PRINTING_TIMEOUT')}</timeout>
			<printjobid>{$this->get_app()->get_config()->get_option('DEFAULT_PRINT_JOB_ID')}</printjobid>
		</parameter>
	</s:Header>
	<s:Body>
		<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
			{$xml}
		</epos-print>
	</s:Body>
</s:Envelope>
XML;
		$query = Psr7DataQuery::create_request('POST', $this->get_app()->get_config()->get_option('WEBSERVICE_URL'), array(), $xml);
		return $this->get_data_connection()->query($query);
	}
	
	protected function get_printer_url(){
		return "http://10.193.1.50/cgi-bin/epos/service.cgi";
	}
	
	public function set_columns($value) {
		$this->get_data_widget()->set_columns($value);
		return $this;
	}
	
	public function get_header_text() {
		return $this->header_text;
	}

    public function isPrintDataDefinedAsTemplate()
    {
        return !empty($this->get_print_template());
    }

	public function set_header_text($value) {
		$this->header_text = $value;
		return $this;
	}

    public function get_print_template()
    {
        return $this->print_template;
    }

    public function set_print_template($print_template)
    {
        $this->print_template = $print_template;
    }

    public function get_device_id()
    {
        if( empty($this->device_id) ) {
            return $this->get_app()->get_config()->get_option('DEFAULT_PRINTER_DEVICE_ID');
        }
        return $this->device_id;
    }

    public function set_device_id($device_id)
    {
        $this->device_id = $device_id;
    }
	
	public function get_document_id_attribute_alias() {
		return $this->document_id_attribute_alias;
	}
	
	public function set_document_id_attribute_alias($value) {
		$this->document_id_attribute_alias = $value;
		return $this;
	}
	
	public function get_footer_text() {
		return $this->footer_text;
	}
	
	public function set_footer_text($value) {
		$this->footer_text = $value;
		return $this;
	}
	
	public function get_footer_barcode() {
		return $this->footer_barcode;
	}
	
	public function set_footer_barcode($value) {
		$this->footer_barcode = $value;
		return $this;
	}
	
	public function get_document_object_relation_path() {
		return $this->document_object_relation_path;
	}
	
	public function set_document_object_relation_path($value) {
		$this->document_object_relation_path = $value;
		return $this;
	}       
	
	/**
	 * 
	 * @return Data
	 */
	protected function get_data_widget(){
		if (is_null($this->data_widget)){
			$page = $this->get_called_on_ui_page();
			$this->data_widget = WidgetFactory::create($page, 'Data', $this->get_called_by_widget());
			$this->data_widget->set_meta_object($this->get_document_object());
		}
		return $this->data_widget;
	}
	
	/**
	 * 
	 * @return string
	 */
	public function get_data_connection_alias() {
		return $this->data_connection_alias;
	}
	
	/**
	 * 
	 * @param string $value
	 * @return \exface\EpsonIHubPrinterConnector\Actions\PrintData
	 */
	public function set_data_connection_alias($value) {
		$this->data_connection_alias = $value;
		return $this;
	}
	
	/**
	 * 
	 * @return DataConnectionInterface
	 */
	protected function get_data_connection(){
		return $this->get_workbench()->data()->get_data_connection($this->get_app()->get_config()->get_option('DATA_SOURCE_UID'), $this->get_data_connection_alias());
	}

    /**
     * @param $documentObject
     * @return \exface\Core\Interfaces\DataSheets\DataSheetInterface
     */
    protected function prepareDataSheet($documentObject)
    {
        $document_data = $this->get_data_widget()->prepare_data_sheet_to_read(DataSheetFactory::create_from_object($documentObject));

        $columns = $this->get_data_widget()->get_columns(); //Are columns defined?
        if( empty($columns) ) {
            //add all columns from object
            foreach ($this->get_document_object()->get_attributes() as $attr) {
                $document_data->get_columns()->add_from_attribute($attr);
            }
        }
        return $document_data;
    }

    protected function isPrintDataDefinedAsColumns(){

        $dataWidgetColumns = $this->get_data_widget()->get_columns();
        return !empty($dataWidgetColumns);
    }

    /**
     * @param $document_data \exface\Core\CommonLogic\DataSheets\DataSheet
     */
    protected function buildPrinterXmlByTemplate($document_data) {

        $xmlPosPrint = "";

        $template = $this->get_print_template();

        foreach ($document_data->get_rows() as $row) {
            $tmp = $template;
            foreach ($row as $key => $value) {
                $tmp = str_replace("{#" . strtoupper($key) . "#}", $value, $tmp);
            }
            //TODO replace missing tags (regex)
            $xmlPosPrint .= $tmp;
        }

        return $xmlPosPrint;
    }


    /**
     * @param $document_data
     * @return string
     */
    protected function buildPrinterXmlByColumns($document_data)
    {
        $xml = '<text>' . $this->get_header_text() . "\n" . '</text>' . "\n";

        $rows = array();
        $column_widths = array();
        $column_alignments = array();
        foreach ($this->get_data_widget()->get_columns() as $col) {
            if ($col->is_hidden()) continue;
            foreach ($document_data->get_columns()->get($col->get_data_column_name())->get_values() as $row => $value) {
                $rows[$row][$col->get_caption()] = $value;
            }
            if ($col->get_width()) {
                $column_widths[$col->get_caption()] = $col->get_width()->get_value();
            }
            if ($col->get_align()) {
                $column_alignments[$col->get_caption()] = $col->get_align();
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

        $xml .= '<text>' . "\n" . $this->get_footer_text() . "\n" . '</text>' . "\n";
        $xml .= $this->build_xml_cut();
        return $xml;
    }

    /**
     * @param $xml
     */
    protected function sendToSpool($xml)
    {
        $pool_ds = DataSheetFactory::create_from_object_id_or_alias($this->get_workbench(), 'exface.EpsonIHubPrinterConnector.PRINT_JOB');
        $pool_ds->get_columns()->add_from_expression('device_id');
        $pool_ds->get_columns()->add_from_expression('content');
        $pool_ds->get_columns()->add_from_expression('state_id');

        $pool_ds->add_row(array('device_id' => $this->get_device_id(), "content" => $this->getPrinterXML($xml), "state_id" => PrintSpoolData::STATE_PRINT_JOB_CREATED));

        $pool_ds->data_create(false);
    }
}
?>