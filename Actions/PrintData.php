<?php namespace exface\EpsonIHubPrinterConnector\Actions;

use exface\Core\CommonLogic\AbstractAction;
use GuzzleHttp\Client;
use exface\Core\Exceptions\ActionRuntimeException;

class PrintData extends AbstractAction {
	protected function perform(){
		$guzzle = new Client();
		$options = array(
			'body' => '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">' .
                '<s:Header>' .
                    '<parameter xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">' .
                        '<devid>local_printer</devid>' .
                        '<timeout>10000</timeout>' .
                        '<printjobid>ABC123</printjobid>' .
                    '</parameter>' .
                '</s:Header>' .
                '<s:Body>' .
                    '<epos-print xmlns="http://www.epson-pos.com/schemas/2011/03/epos-print">' .
                        '<text lang="en" smooth="true">Intelligent Printer&#10;</text>' .
                        '<barcode type="ean13" width="2" height="48">201234567890</barcode>' .
                        '<feed unit="24"/>' .
                        '<image width="8" height="48">8PDw8A8PDw/w8PDwDw8PD/Dw8PAPDw8P8PDw8A8PDw/w8PDwDw8PD/Dw8PAPDw8P</image>' .
                        '<cut/>' .
                    '</epos-print>' .
                '</s:Body>' .
            '</s:Envelope>'
		);
		$result = $guzzle->post($this->get_printer_url(), $options);
		if ($result->getStatusCode() == 200){
			$this->set_result_message('Document sent to printer');
		} else {
			throw new ActionRuntimeException('Could not print on "' . $this->get_printer_url() . '" (Error "' . $result->getStatusCode() . '")!');
		}
	}
	
	protected function get_printer_url(){
		return "http://10.193.1.50/cgi-bin/epos/service.cgi";
	}
}

?>