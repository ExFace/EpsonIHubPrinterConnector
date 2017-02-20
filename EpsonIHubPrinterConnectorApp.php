<?php namespace exface\EpsonIHubPrinterConnector;
				
use exface\Core\Interfaces\InstallerInterface;
use exface\SqlDataConnector\SqlSchemaInstaller;

class EpsonIHubPrinterConnectorApp extends \exface\Core\CommonLogic\AbstractApp {
	
	/**
	 * The printer connector uses a database table for print job spooling. To maintain this table, the SqlSchemaInstaller
	 * is used with the data connection of the current model loader.
	 * 
	 * {@inheritDoc}
	 * @see \exface\Core\CommonLogic\AbstractApp::get_installer()
	 */
	public function get_installer(InstallerInterface $injected_installer = null){
		$installer = parent::get_installer($injected_installer);
	
		$schema_installer = new SqlSchemaInstaller($this->get_name_resolver());
		$schema_installer->set_data_connection($this->get_workbench()->model()->get_model_loader()->get_data_connection());
		$installer->add_installer($schema_installer);
			
		return $installer;
	}
}	