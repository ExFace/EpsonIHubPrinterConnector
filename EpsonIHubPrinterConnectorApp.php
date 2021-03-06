<?php
namespace exface\EpsonIHubPrinterConnector;

use exface\Core\Interfaces\InstallerInterface;
use exface\Core\CommonLogic\AppInstallers\SqlSchemaInstaller;
use exface\Core\CommonLogic\Model\App;

class EpsonIHubPrinterConnectorApp extends App
{

    /**
     * The printer connector uses a database table for print job spooling.
     * To maintain this table, the SqlSchemaInstaller
     * is used with the data connection of the current model loader.
     *
     * {@inheritdoc}
     *
     * @see App::getInstaller()
     */
    public function getInstaller(InstallerInterface $injected_installer = null)
    {
        $installer = parent::getInstaller($injected_installer);
        
        $schema_installer = new SqlSchemaInstaller($this->getSelector());
        $schema_installer->setDataConnection($this->getWorkbench()->model()->getModelLoader()->getDataConnection());
        $schema_installer->setLastUpdateIdConfigOption('LAST_PERFORMED_MODEL_SOURCE_UPDATE_ID');
        $installer->addInstaller($schema_installer);
        
        // TODO add a custom installer like in the MODx CMS connector, that will add the following lines to .htaccess
        // # Epson iHub printer server
        // RewriteRule ^iHubPrintServer/([^/]+)/$ exface/exface.php?exftpl=exface.JEasyUIFacade&action=exface.EpsonIHubPrinterConnector.PrintSpoolData&printer=$1 [L]
        
        return $installer;
    }
}	