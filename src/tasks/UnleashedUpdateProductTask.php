<?php

namespace AntonyThorpe\SilverShopUnleashed;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use DateTime;
use DateTimeZone;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\Debug;
use SilverStripe\Control\Email\Email;
use SilverShop\Page\Product;
use SilverShop\Page\ProductCategory;
use SilverShop\Extension\ShopConfigExtension;
use AntonyThorpe\Consumer\Consumer;
use AntonyThorpe\Consumer\Utilities;

/**
 * Update Products with fresh data from Unleashed Inventory Management Software
 *
 * @package silvershop-unleashed
 * @subpackage tasks
 */

abstract class UnleashedUpdateProductTask extends UnleashedBuildTask
{
    /**
     * @var string
     */
    protected $title = "Unleashed: Update Products";

    /**
     * @var string
     */
    protected $description = "Update the Products in Silvershop with data from Unleashed.  Will not automatically bring over new items but will update Titles, Base Price, etc.";

    protected $email_subject = "API Unleashed Software - Update Product Results";

    public function run($request)
    {
        // Definitions
        $silvershopInternalItemIDMustBeUnique = Product::get()->column('InternalItemID');
        $silvershopTitleMustBeUnique = Product::get()->column('Title');
        $consumer = Consumer::get()->find('Title', 'ProductUpdate');

        // Get Products from Unleashed
        if (!$consumer) {
            $response = UnleashedAPI::sendCall(
                'GET',
                'https://api.unleashedsoftware.com/Products'
            );

            $apidata_array = json_decode($response->getBody()->getContents(), true);
            $apidata = $apidata_array['Items'];
            $pagination = $apidata_array['Pagination'];
            $numberofpages = (int) $pagination['NumberOfPages'];

            if ($numberofpages > 1) {
                for ($i = 2; $i <= $numberofpages; $i++) {
                    $response = UnleashedAPI::sendCall(
                        'GET',
                        'https://api.unleashedsoftware.com/Products/' . $i
                    );
                    $apidata_array = json_decode($response->getBody()->getContents(), true);
                    $apidata = array_merge($apidata, $apidata_array['Items']);
                }
            }
        } else {
            $query = [];
            $date = new DateTime($consumer->ExternalLastEdited);
            $date->setTimezone(new DateTimeZone("UTC"));  // required for Unleashed Products (not for other endpoints)
            $query['modifiedSince'] = substr($date->format('Y-m-d\TH:i:s.u'), 0, 23);

            $response = UnleashedAPI::sendCall(
                'GET',
                'https://api.unleashedsoftware.com/Products',
                ['query' => $query]
            );

            $apidata_array = json_decode($response->getBody()->getContents(), true);
            $apidata = $apidata_array['Items'];
            $pagination = $apidata_array['Pagination'];
            $numberofpages = (int) $pagination['NumberOfPages'];

            if ($numberofpages > 1) {
                for ($i = 2; $i <= $numberofpages; $i++) {
                    $response = UnleashedAPI::sendCall(
                        'GET',
                        'https://api.unleashedsoftware.com/Products/' . $i,
                        ['query' => $query]
                    );
                    $apidata_array = json_decode($response->getBody()->getContents(), true);
                    $apidata = array_merge($apidata, $apidata_array['Items']);
                }
            }
        }

        $this->log('<h2>Preliminary Checks</h2>');
        // Check for duplicates in DataList before proceeding further
        $duplicates = Utilities::getDuplicates($silvershopInternalItemIDMustBeUnique);
        if (!empty($duplicates)) {
            echo "<h2>Duplicate check of Product InternalItemID within Silvershop</h2>";
            foreach ($duplicates as $duplicate) {
                $this->log($duplicate);
            }
            $this->log('Please remove duplicates from Silvershop before running this Build Task');
            $this->log('Exit');
            die();
        } else {
            $this->log('No duplicate found');
        }

        $duplicates = Utilities::getDuplicates($silvershopTitleMustBeUnique);
        if (!empty($duplicates)) {
            echo "<h2>Duplicate check of Product Titles within Silvershop</h2>";
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
        $duplicates = Utilities::getDuplicates(array_column($apidata, 'ProductCode'));
        if (!empty($duplicates)) {
            echo "<h2>Duplicate check of ProductCode within Unleashed</h2>";
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

        // Update
        $this->log('<h3>Update Product records in Silvershop</h3>');
        $loader = ProductBulkLoader::create('SilverShop\Page\Product');
        $loader->transforms = [
            'Parent' => [
                'callback' => function ($value) {
                    $obj = ProductCategory::get()->find('Guid', $value['Guid']);
                    if ($obj) {
                        return $obj;
                    } else {
                        return ProductCategory::get()->find('Title', $value['GroupName']);
                    }
                }
            ],
            'BasePrice' => [
                'callback' => function ($value) {
                    return (float)$value;
                }
            ],
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
        Debug::show($results->Count());
        Debug::show(!$this->preview);
        Debug::show(Email::config()->admin_email);
        Debug::show($this->email_subject);
        // Send email
        if ($results->Count() && !$this->preview && Email::config()->admin_email && $this->email_subject) {
            // send email
            $email = Email::create(
                ShopConfigExtension::config()->email_from ? ShopConfigExtension::config()->email_from : Email::config()->admin_email,
                Email::config()->admin_email,
                $this->email_subject,
                Debug::text($results->getData())
            );
            $dispatched = $email->send();
            if ($dispatched) {
                $this->log('Email sent');
            }
        }

        if (!$this->preview && $apidata) {
            if (!$consumer) {
                $consumer = Consumer::create([
                    'Title' => 'ProductUpdate',
                    'ExternalLastEditedKey' => 'LastModifiedOn'
                ]);
            }
            $consumer->setMaxExternalLastEdited($apidata);
            $consumer->write();
        }
    }
}
