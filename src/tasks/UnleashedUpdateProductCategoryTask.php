<?php

namespace AntonyThorpe\SilverShopUnleashed;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use SilverStripe\Dev\Debug;
use SilverStripe\Core\Convert;
use SilverStripe\Control\Email\Email;
use SilverShop\Extension\ShopConfigExtension;
use SilverShop\Page\ProductCategory;
use AntonyThorpe\Consumer\Utilities;

/**
 * Update ProductCategory with fresh data from Unleashed Software Inventory system
 *
 * @package silvershop-unleashed
 * @subpackage tasks
 */

abstract class UnleashedUpdateProductCategoryTask extends UnleashedBuildTask
{
    /**
     * @var string
     */
    protected $title = "Unleashed: Update Product Categories";

    /**
     * @var string
     */
    protected $description = "Update Product Categories in Silvershop with with Product Group data from Unleashed.  Will not automatically bring over new items but will update Title and record the Guid.";

    protected $email_subject = "API Unleashed Software - Update Product Categories Results";

    public function run($request)
    {
        // Definitions
        $silvershopDataListMustBeUnique = ProductCategory::get()->column('Title');

        // Get Product Categories from Unleashed
        $response = UnleashedAPI::sendCall(
            'GET',
            'https://api.unleashedsoftware.com/ProductGroups'
        );

        // Extract data
        $apidata_array = json_decode($response->getBody()->getContents(), true);
        if (isset($apidata_array)) {
            $apidata = $apidata_array['Items'];
        }

        $this->log('<h2>Preliminary Checks</h2>');
        // Check for duplicates in DataList before proceeding further
        $duplicates = Utilities::getDuplicates($silvershopDataListMustBeUnique);
        if (!empty($duplicates)) {
            echo "<h2>Duplicate check of Product Categories within Silvershop</h2>";
            foreach ($duplicates as $duplicate) {
                $this->log($duplicate);
            }
            $this->log('Please remove duplicates from Silvershop before running this Build Task');
            $this->log('Exit');
            die();
        } else {
            $this->log('No duplicate found');
        }

        // Check for duplicates in apidata before proceeding further
        $duplicates = Utilities::getDuplicates(array_column($apidata, 'GroupName'));
        if (!empty($duplicates)) {
            echo "<h2>Duplicate check of Product Categories within Unleashed</h2>";
            foreach ($duplicates as $duplicate) {
                $this->log(htmlspecialchars($duplicate, ENT_QUOTES, 'utf-8'));
            }
            $this->log(
                'Please remove duplicates from Unleashed before running this Build Task'
            );
            $this->log('Exit');
            die();
        } else {
            $this->log('No duplicate found');
        }


        $this->log('<h2>Update Silvershop Product Categories from Unleashed</h2>');
        $loader_clear = ProductCategoryBulkLoader::create('SilverShop\Page\ProductCategory');

        $this->log('<h3>Clear a Guid from the Silvershop Product Category if it does not exist</h3>');
        $results_absent = $loader_clear->clearAbsentRecords($apidata, 'Guid', 'Guid', $this->preview);
        if ($results_absent->UpdatedCount()) {
            $this->log(Debug::text($results_absent->getData()));
        }
        $this->log('Done');


        $this->log('<h3>Update Product Category records in Silvershop</h3>');
        $loader = ProductCategoryBulkLoader::create('SilverShop\Page\ProductCategory');
        $loader->transforms = [
            'Title' => [
                'callback' => function ($value, &$placeholder) {
                    $placeholder->URLSegment = Convert::raw2url($value);
                    return $value;
                }
            ]
        ];
        $results = $loader->updateRecords($apidata, $this->preview);
        if ($results->UpdatedCount()) {
            $this->log(Debug::text($results->getData()));
        }
        $this->log("Done");

        // Send email
        $count = $results_absent->Count() + $results->Count();
        if ($count && $this->email_subject && !$this->preview && Email::config()->admin_email) {
            $data = $results_absent->getData();
            $data->merge($results->getData());
            $email = Email::create(
                ShopConfigExtension::config()->email_from ? ShopConfigExtension::config()->email_from : Email::config()->admin_email,
                Email::config()->admin_email,
                $this->email_subject,
                Debug::text($data)
            );
            $dispatched = $email->send();
            if ($dispatched) {
                $this->log('<h3>Email</h3>');
                $this->log('Sent');
            }
        }
    }
}
