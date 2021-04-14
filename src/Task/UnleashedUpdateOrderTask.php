<?php

namespace AntonyThorpe\SilverShopUnleashed\Task;

use AntonyThorpe\Consumer\Consumer;
use AntonyThorpe\SilverShopUnleashed\BulkLoader\OrderBulkLoader;
use AntonyThorpe\SilvershopUnleashed\Defaults;
use AntonyThorpe\SilverShopUnleashed\Task\UnleashedBuildTask;
use AntonyThorpe\SilverShopUnleashed\UnleashedAPI;
use DateTime;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use SilverShop\Extension\ShopConfigExtension;
use SilverStripe\Control\Email\Email;
use SilverStripe\Dev\Debug;

/**
 * Update Order with fresh data from Unleashed's Sales Orders
 */
abstract class UnleashedUpdateOrderTask extends UnleashedBuildTask
{
    /**
     * @var string
     */
    protected $title = "Unleashed: Update Orders";

    /**
     * @var string
     */
    protected $description = "Update Orders in Silvershop with with data received from Unleashed.  Will update the OrderStatus of the Silvershop items.";

    protected $email_subject = "API Unleashed Software - Update Order Results";

    /**
     * Order status map from Unleashed to Silvershop
     *
     * For converting from Unleashed Sales Order Status to Silvershop
     * @var array
     */
    protected $order_status_map = [
        'Open' => 'Unpaid',
        'Parked' => 'Paid',
        'Backordered' => 'Processing',
        'Placed' => 'Processing',
        'Picking' => 'Processing',
        'Picked' => 'Processing',
        'Packed' => 'Processing',
        'Dispatched' => 'Sent',
        'Complete' => 'Complete',
        'Deleted' => 'MemberCancelled'
    ];

    public function run($request)
    {
        // Definitions
        $default_source_id = Defaults::config()->source_id;
        $query = [];
        $consumer = Consumer::get()->find('Title', 'OrderUpdate');  // to get modifiedSince

        if (!empty($consumer)) {
            $date = new DateTime($consumer->ExternalLastEdited);
            $query['modifiedSince'] = substr($date->format('Y-m-d\TH:i:s.u'), 0, 23);
        }

        if ($default_source_id) {
            $query['sourceId'] = $default_source_id;
        }

        $response = UnleashedAPI::sendCall(
            'GET',
            'https://api.unleashedsoftware.com/SalesOrders',
            ['query' => $query]
        );

        if ($response->getStatusCode() == '200') {
            $apidata_array = json_decode($response->getBody()->getContents(), true);
            $apidata = $apidata_array['Items'];
            $pagination = $apidata_array['Pagination'];
            $numberofpages = (int) $pagination['NumberOfPages'];

            if ($numberofpages > 1) {
                for ($i = 2; $i <= $numberofpages; $i++) {
                    $response = UnleashedAPI::sendCall(
                        'GET',
                        'https://api.unleashedsoftware.com/SalesOrders/' . $i,
                        ['query' => $query]
                    );
                    if ($response->getStatusCode() == '200') {
                        $apidata_array = json_decode($response->getBody()->getContents(), true);
                        $apidata = array_merge($apidata, $apidata_array['Items']);
                    }
                }
            }

            $this->log('<h3>Update the OrderStatus in Silvershop</h3>');
            $loader = OrderBulkLoader::create('SilverShop\Model\Order');
            $loader->transforms = [
                'Status' => [
                    'callback' => function ($value) {
                        // convert from Unleashed Sales Order status to Silvershop
                        return $this->order_status_map[$value];
                    }
                ]
            ];
            $results = $loader->updateRecords($apidata, $this->preview);

            if ($results->UpdatedCount()) {
                $this->log(Debug::text($results->getData()));
            }
            $this->log("Done");

            // Send email
            if ($results->Count() && $this->email_subject && !$this->preview && Email::config()->admin_email) {
                $data = $results->getData();
                $email = Email::create(
                    ShopConfigExtension::config()->email_from ? ShopConfigExtension::config()->email_from : Email::config()->admin_email,
                    Email::config()->admin_email,
                    $this->email_subject,
                    Debug::text($data)
                );
                $dispatched = $email->send();
                if ($dispatched) {
                    $this->log("Email sent");
                }
            }

            // Create/update Consumer
            if (!$this->preview && $apidata) {
                if (empty($consumer)) {
                    $newItem = Consumer::create([
                        'Title' => 'OrderUpdate',
                        'ExternalLastEditedKey' => 'LastModifiedOn'
                    ]);
                }
                $newItem->setMaxExternalLastEdited($apidata);
                $newItem->write();
            }
        } // end if response == 200
    }
}
