<?php namespace exface\EpsonIHubPrinterConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use GuzzleHttp\Client;
use exface\Core\Exceptions\ActionRuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\Data;
use kabachello\phpTextTable\TextTable;

class PrintData extends AbstractAction {
	private $document_object_relation_path = null;
	private $document_id_attribute_alias = null;
	private $data_widget = null;
	private $header_text = null;
	private $footer_text = null;
	private $footer_barcode = null;
	
	protected function perform(){
		$xml = '<text>' . $this->get_header_text() ."\n" . '</text>'  ."\n";
		$document_data = $this->get_data_widget()->prepare_data_sheet_to_read(DataSheetFactory::create_from_object($this->get_document_object()));
		if ($this->get_document_object_relation_path()){
			$rev_path = $this->get_meta_object()->get_relation($this->get_document_object_relation_path())->get_reversed_relation()->get_alias();
			$document_data->add_filter_from_string($rev_path, implode(',', array_unique($this->get_input_data_sheet()->get_uid_column()->get_values(false))));
		} else {
			// TODO
		}
		$document_data->data_read();
		
		$rows = array();
		$column_widths = array();
		$column_alignments = array();
		foreach ($this->get_data_widget()->get_columns() as $col){
			if ($col->is_hidden()) continue;
			foreach ($document_data->get_columns()->get($col->get_data_column_name())->get_values() as $row => $value){
				$rows[$row][$col->get_caption()] = $value;
			}
			if ($col->get_width()){
				$column_widths[$col->get_caption()] = $col->get_width()->get_value();
			}
			if ($col->get_align()){
				$column_alignments[$col->get_caption()] = $col->get_align();
			}
		}
		
		$text_table = new TextTable($rows);
		$text_table->set_column_alignments($column_alignments);
		$text_table->set_column_width_max($column_widths);
		$text_table->set_column_width_auto(false);
		$text_table->set_print_header(false);
		$text_table->set_separator_column('');
		$text_table->set_separator_crossing('');
		$text_table->set_separator_row('');
		
		foreach (array_keys($rows) as $row){
			$xml .= '<text>' . $text_table->print($row) . '</text>' ."\n";	
		}
		
		$xml .= '<text>' . "\n" . $this->get_footer_text() . "\n" . '</text>'  ."\n";
		$xml .= $this->build_xml_cut();
		
		$result = $this->print($xml);
		if ($result->getStatusCode() == 200){
			$this->set_result_message('Document sent to printer');
		} else {
			throw new ActionRuntimeException('Could not print on "' . $this->get_printer_url() . '" (Error "' . $result->getStatusCode() . '")!');
		}
	}
	
	protected function get_document_object(){
		if ($this->get_document_object_relation_path()){
			$document_object = $this->get_meta_object()->get_related_object($this->get_document_object_relation_path());
		} else {
			$document_object = $this->get_meta_object()->get_meta_object();
		}
		return $document_object;
	}
	
	protected function build_xml_cut(){
		return '<cut/>';
	}
	
	protected function print($xml){
		//print($xml);
		$xml = <<<XML
<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
	<s:Header>
		<parameter xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
			<devid>local_printer</devid>
			<timeout>10000</timeout>
			<printjobid>ABC123</printjobid>
		</parameter>
	</s:Header>
	<s:Body>
		<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">
			{$xml}
		</epos-print>
	</s:Body>
</s:Envelope>
XML;
		$guzzle = new Client();
		$options = array(
				'body' => $xml
		);
		$result = $guzzle->post($this->get_printer_url(), $options);
		return $result;
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
	
	public function set_header_text($value) {
		$this->header_text = $value;
		return $this;
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
}

?>