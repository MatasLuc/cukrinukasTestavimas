<?php

namespace Paysera\DeliveryApi\MerchantClient;

use Paysera\Component\RestClientCommon\Exception\ClientException;
use Paysera\DeliveryApi\MerchantClient\Entity as Entities;
use Fig\Http\Message\RequestMethodInterface;
use Paysera\Component\RestClientCommon\Client\ApiClient;
use Paysera\Component\RestClientCommon\Entity\File;
use Paysera\Component\RestClientCommon\Entity\Filter;
use Paysera\DeliveryApi\MerchantClient\Entity\ProjectCredentials;

class MerchantClient
{
    private $apiClient;

    public function __construct(ApiClient $apiClient)
    {
        $this->apiClient = $apiClient;
    }

    public function withOptions(array $options)
    {
        return new MerchantClient($this->apiClient->withOptions($options));
    }

    /**
     * Standard SQL-style Result filtering
     * GET /default-package-sizes
     *
     * @param Filter $filter
     * @return Entities\DefaultPackageSizeCollection
     */
    public function getDefaultPackageSizes(Filter $filter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'default-package-sizes',
            $filter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\DefaultPackageSizeCollection($data);
    }

    /**
     * Get shipment method by id
     * GET /methods/{id}
     *
     * @param string $id
     * @return Entities\ShipmentMethod
     */
    public function getMethod($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('methods/%s', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentMethod($data);
    }

    /**
     * Get shipment methods
     * PUT /methods
     *
     * @param Entities\MethodsFilter $methodsFilter
     * @return Entities\ShipmentMethodCollection
     */
    public function updateMethod(Entities\MethodsFilter $methodsFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            'methods',
            $methodsFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentMethodCollection($data);
    }

    /**
     * Gateway methods filter
     * GET /gateways/{id}/methods
     *
     * @param string $id
     * @param Entities\GatewayMethodsFilter $gatewayMethodsFilter
     * @return Entities\ShipmentMethodCollection
     */
    public function getGatewayMethods($id, Entities\GatewayMethodsFilter $gatewayMethodsFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('gateways/%s/methods', rawurlencode($id)),
            $gatewayMethodsFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentMethodCollection($data);
    }

    /**
     * Get shipment gateway by id
     * GET /gateways/{id}
     *
     * @param string $id
     * @return Entities\ShipmentGateway
     */
    public function getGateway($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('gateways/%s', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentGateway($data);
    }

    /**
     * Get shipment gateways
     * PUT /gateways
     *
     * @param Entities\GatewaysFilter $gatewaysFilter
     * @return Entities\ShipmentGatewayCollection
     */
    public function updateGateway(Entities\GatewaysFilter $gatewaysFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            'gateways',
            $gatewaysFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentGatewayCollection($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /post-offices
     *
     * @param Entities\PostOfficeFilter $postOfficeFilter
     * @return Entities\PostOfficeCollection
     */
    public function getPostOffices(Entities\PostOfficeFilter $postOfficeFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'post-offices',
            $postOfficeFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\PostOfficeCollection($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /parcel-machines
     *
     * @param Entities\ParcelMachineFilter $parcelMachineFilter
     * @return Entities\ParcelMachineCollection
     */
    public function getParcelMachines(Entities\ParcelMachineFilter $parcelMachineFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'parcel-machines',
            $parcelMachineFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ParcelMachineCollection($data);
    }

    /**
     * Import shipment points from file
     * POST /shipment-points-import
     *
     * @param File $file
     * @return Entities\ShipmentPointCollection
     */
    public function createShipmentPointsImport(File $file)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'shipment-points-import',
            $file
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentPointCollection($data);
    }

    /**
     * Get shipment point by id
     * GET /shipment-points/{id}
     *
     * @param string $id
     * @return Entities\ShipmentPoint
     */
    public function getShipmentPoint($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('shipment-points/%s', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentPoint($data);
    }

    /**
     * Update shipment-point
     * PUT /shipment-points/{id}
     *
     * @param string $id
     * @param Entities\ShipmentPointCreate $shipmentPointCreate
     * @return Entities\ShipmentPoint
     */
    public function updateShipmentPoint($id, Entities\ShipmentPointCreate $shipmentPointCreate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('shipment-points/%s', rawurlencode($id)),
            $shipmentPointCreate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentPoint($data);
    }

    /**
     * Delete shipment point
     * DELETE /shipment-points/{id}
     *
     * @param string $id
     * @return null
     */
    public function deleteShipmentPoint($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_DELETE,
            sprintf('shipment-points/%s', rawurlencode($id)),
            null
        );
        $this->apiClient->makeRequest($request);

        return null;
    }

    /**
     * Add a new shipment point
     * POST /shipment-points
     *
     * @param Entities\ShipmentPointCreate $shipmentPointCreate
     * @return Entities\ShipmentPoint
     */
    public function createShipmentPoint(Entities\ShipmentPointCreate $shipmentPointCreate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'shipment-points',
            $shipmentPointCreate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentPoint($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /shipment-points
     *
     * @param Entities\ShipmentPointFilter $shipmentPointFilter
     * @return Entities\ShipmentPointCollection
     */
    public function getShipmentPoints(Entities\ShipmentPointFilter $shipmentPointFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'shipment-points',
            $shipmentPointFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentPointCollection($data);
    }

    /**
     * Import orders from file
     * POST /orders-import
     *
     * @param File $file
     * @return Entities\OrderCollection
     */
    public function createOrdersImport(File $file)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'orders-import',
            $file
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\OrderCollection($data);
    }

    /**
     * Get current default package size
     * GET /projects/{projectId}/default-package-size
     *
     * @param string $projectId
     * @return Entities\DefaultPackageSizeSet
     */
    public function getProjectDefaultPackageSize($projectId)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('projects/%s/default-package-size', rawurlencode($projectId)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\DefaultPackageSizeSet($data);
    }

    /**
     * Update default package size
     * PUT /projects/{projectId}/default-package-size
     *
     * @param string $projectId
     * @param Entities\DefaultPackageSizeCode $defaultPackageSizeCode
     * @return Entities\DefaultPackageSize
     */
    public function updateProjectDefaultPackageSize($projectId, Entities\DefaultPackageSizeCode $defaultPackageSizeCode)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('projects/%s/default-package-size', rawurlencode($projectId)),
            $defaultPackageSizeCode
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\DefaultPackageSize($data);
    }

    /**
     * Remove default package size
     * DELETE /projects/{projectId}/default-package-size
     *
     * @param string $projectId
     * @return null
     */
    public function deleteProjectDefaultPackageSize($projectId)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_DELETE,
            sprintf('projects/%s/default-package-size', rawurlencode($projectId)),
            null
        );
        $this->apiClient->makeRequest($request);

        return null;
    }

    /**
     * Update project shipment gateway
     * PUT /projects/{projectId}/gateways/{gatewayId}
     *
     * @param string $projectId
     * @param string $gatewayId
     * @param Entities\ShipmentGatewayCreate $shipmentGatewayCreate
     * @return Entities\ShipmentGateway
     */
    public function updateProjectGateway($projectId, $gatewayId, Entities\ShipmentGatewayCreate $shipmentGatewayCreate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('projects/%s/gateways/%s', rawurlencode($projectId), rawurlencode($gatewayId)),
            $shipmentGatewayCreate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentGateway($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /projects/{projectId}/gateways
     *
     * @param string $projectId
     * @param Filter $filter
     * @return Entities\ShipmentGatewayCollection
     */
    public function getProjectGateways($projectId, Filter $filter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('projects/%s/gateways', rawurlencode($projectId)),
            $filter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentGatewayCollection($data);
    }

    /**
     * Update project gateways
     * PUT /projects/{projectId}/gateways
     *
     * @param string $projectId
     * @param Entities\ShipmentGatewayCreateCollection $shipmentGatewayCreateCollection
     * @return Entities\ShipmentGateway
     */
    public function updateProjectGateways($projectId, Entities\ShipmentGatewayCreateCollection $shipmentGatewayCreateCollection)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('projects/%s/gateways', rawurlencode($projectId)),
            $shipmentGatewayCreateCollection
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentGateway($data);
    }

    /**
     * Update project shipment method
     * PUT /projects/{projectId}/methods/{methodId}
     *
     * @param string $projectId
     * @param string $methodId
     * @param Entities\ShipmentMethodCreate $shipmentMethodCreate
     * @return Entities\ShipmentMethod
     */
    public function updateProjectMethod($projectId, $methodId, Entities\ShipmentMethodCreate $shipmentMethodCreate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('projects/%s/methods/%s', rawurlencode($projectId), rawurlencode($methodId)),
            $shipmentMethodCreate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentMethod($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /projects/{projectId}/methods
     *
     * @param string $projectId
     * @param Filter $filter
     * @return Entities\ShipmentMethodCollection
     */
    public function getProjectMethods($projectId, Filter $filter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('projects/%s/methods', rawurlencode($projectId)),
            $filter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentMethodCollection($data);
    }

    /**
     * Update project methods
     * PUT /projects/{projectId}/methods
     *
     * @param string $projectId
     * @param Entities\ShipmentMethodCreateCollection $shipmentMethodCreateCollection
     * @return Entities\ShipmentMethod
     */
    public function updateProjectMethods($projectId, Entities\ShipmentMethodCreateCollection $shipmentMethodCreateCollection)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('projects/%s/methods', rawurlencode($projectId)),
            $shipmentMethodCreateCollection
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ShipmentMethod($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /projects
     *
     * @param Filter $filter
     * @return Entities\ProjectCollection
     */
    public function getProjects(Filter $filter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'projects',
            $filter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ProjectCollection($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /orders-export
     *
     * @param Entities\OrderFilter $orderFilter
     * @return File
     */
    public function getOrdersExport(Entities\OrderFilter $orderFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'orders-export',
            $orderFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new File($data);
    }

    /**
     * Confirm orders
     * POST /orders-confirm
     *
     * @param Entities\OrderIdsList $orderIdsList
     * @return Entities\OrderCollection
     */
    public function createOrdersConfirm(Entities\OrderIdsList $orderIdsList)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'orders-confirm',
            $orderIdsList
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\OrderCollection($data);
    }

    /**
     * Prepaid orders
     * Only orders with initial statuses will be processed
     * POST /orders-prepaid
     *
     * @param Entities\OrderIdsList $orderIdsList
     */
    public function createOrdersPrepaid(Entities\OrderIdsList $orderIdsList)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'orders-prepaid',
            $orderIdsList
        );
        $this->apiClient->makeRequest($request);
    }

    /**
     * Generate manifest and call courier for "label_generated" order
     * POST /orders/{id}/manifest
     *
     * @param string $id
     * @return Entities\Order
     */
    public function createOrderManifest($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            sprintf('orders/%s/manifest', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\Order($data);
    }

    /**
     * Get manifest file
     * GET /orders/{id}/manifest
     *
     * @param string $id
     * @return File
     */
    public function getOrderManifest($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('orders/%s/manifest', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new File($data);
    }

    /**
     * Generate labels for "in progress" order
     * POST /orders/{id}/label
     *
     * @param string $id
     * @return Entities\Order
     */
    public function createOrderLabel($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            sprintf('orders/%s/label', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\Order($data);
    }

    /**
     * Get label file
     * GET /orders/{id}/label
     *
     * @param string $id
     * @return File
     */
    public function getOrderLabel($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('orders/%s/label', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new File($data);
    }

    /**
     * reset order to draft state
     * PUT /orders/{id}/reset-to-draft
     *
     * @param string $id
     * @return Entities\Order
     */
    public function resetOrderToDraft($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('orders/%s/reset-to-draft', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\Order($data);
    }

    /**
     * Get order by id
     * GET /orders/{id}
     *
     * @param string $id
     * @return Entities\Order
     */
    public function getOrder($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('orders/%s', rawurlencode($id)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\Order($data);
    }

    /**
     * Update order
     * PUT /orders/{id}
     *
     * @param string $id
     * @param Entities\OrderUpdate $orderUpdate
     * @return Entities\Order
     */
    public function updateOrder($id, Entities\OrderUpdate $orderUpdate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('orders/%s', rawurlencode($id)),
            $orderUpdate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\Order($data);
    }

    /**
     * Delete order
     * DELETE /orders/{id}
     *
     * @param string $id
     * @return null
     */
    public function deleteOrder($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_DELETE,
            sprintf('orders/%s', rawurlencode($id)),
            null
        );
        $this->apiClient->makeRequest($request);

        return null;
    }

    /**
     * Add a new order
     * POST /orders
     *
     * @param Entities\OrderCreate $orderCreate
     * @return Entities\Order
     */
    public function createOrder(Entities\OrderCreate $orderCreate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'orders',
            $orderCreate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\Order($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /orders
     *
     * @param Entities\OrderFilter $orderFilter
     * @return Entities\OrderCollection
     */
    public function getOrders(Entities\OrderFilter $orderFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'orders',
            $orderFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\OrderCollection($data);
    }

    /**
     * List order prices
     * PUT /order-prices
     *
     * @param Entities\OrderPriceFilter $orderPriceFilter
     * @return Entities\OrderPriceCollection
     */
    public function updateOrderPrice(Entities\OrderPriceFilter $orderPriceFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            'order-prices',
            $orderPriceFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\OrderPriceCollection($data);
    }

    /**
     * Activity filter
     * GET /statistics/export
     *
     * @param Entities\ActivityFilter $activityFilter
     * @return File
     */
    public function getStatisticExport(Entities\ActivityFilter $activityFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'statistics/export',
            $activityFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new File($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /statistics/last-activity
     *
     * @param Entities\LastActivityFilter $lastActivityFilter
     * @return Entities\LastActivityCollection
     */
    public function getStatisticLastActivity(Entities\LastActivityFilter $lastActivityFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'statistics/last-activity',
            $lastActivityFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\LastActivityCollection($data);
    }

    /**
     * Activity filter
     * GET /statistics
     *
     * @param Entities\ActivityFilter $activityFilter
     * @return Entities\ActivityCollection
     */
    public function getStatistics(Entities\ActivityFilter $activityFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'statistics',
            $activityFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\ActivityCollection($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /countries
     *
     * @param Entities\CountryFilter $countryFilter
     * @return Entities\CountriesCollection
     */
    public function getCountries(Entities\CountryFilter $countryFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'countries',
            $countryFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\CountriesCollection($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /cities
     *
     * @param Entities\CityFilter $cityFilter
     * @return Entities\CityCollection
     */
    public function getCities(Entities\CityFilter $cityFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'cities',
            $cityFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\CityCollection($data);
    }

    /**
     * List credentials
     * GET /courier-api-credentials/{projectId}
     *
     * @param string $projectId
     * @return Entities\CourierApiCredentialsCollection
     */
    public function getCourierApiCredential($projectId)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            sprintf('courier-api-credentials/%s', rawurlencode($projectId)),
            null
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\CourierApiCredentialsCollection($data);
    }

    /**
     * Update credentials
     * PUT /courier-api-credentials/{id}
     *
     * @param string $id
     * @param Entities\CourierApiCredentialsCreate $courierApiCredentialsCreate
     * @return Entities\CourierApiCredentials
     */
    public function updateCourierApiCredential($id, Entities\CourierApiCredentialsCreate $courierApiCredentialsCreate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_PUT,
            sprintf('courier-api-credentials/%s', rawurlencode($id)),
            $courierApiCredentialsCreate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\CourierApiCredentials($data);
    }

    /**
     * Delete credentials
     * DELETE /courier-api-credentials/{id}
     *
     * @param string $id
     * @return null
     */
    public function deleteCourierApiCredential($id)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_DELETE,
            sprintf('courier-api-credentials/%s', rawurlencode($id)),
            null
        );
        $this->apiClient->makeRequest($request);

        return null;
    }

    /**
     * Create new credentials
     * POST /courier-api-credentials
     *
     * @param Entities\CourierApiCredentialsCreate $courierApiCredentialsCreate
     * @return Entities\CourierApiCredentials
     */
    public function createCourierApiCredential(Entities\CourierApiCredentialsCreate $courierApiCredentialsCreate)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'courier-api-credentials',
            $courierApiCredentialsCreate
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\CourierApiCredentials($data);
    }

    /**
     * Standard SQL-style Result filtering
     * GET /orders-count
     *
     * @param Entities\OrderFilter $orderFilter
     * @return Entities\OrdersStatesCountCollection
     */
    public function getOrdersCount(Entities\OrderFilter $orderFilter)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_GET,
            'orders-count',
            $orderFilter
        );
        $data = $this->apiClient->makeRequest($request);

        return new Entities\OrdersStatesCountCollection($data);
    }

    /**
     * Validate project credentials
     * POST /projects/validate-credentials
     * This endpoint is not protected by authentication
     *
     * @param ProjectCredentials $credentials The credentials to validate (project_id and password)
     * @return bool Returns true if credentials are valid (HTTP 204), false if invalid (HTTP 401)
     * @throws ClientException If validation fails due to rate limiting (HTTP 429),
     *                         server errors (HTTP 5xx), or other API errors (except 401)
     */
    public function validateProjectCredentials(ProjectCredentials $credentials)
    {
        $request = $this->apiClient->createRequest(
            RequestMethodInterface::METHOD_POST,
            'projects/validate-credentials',
            $credentials
        );

        try {
            $response = $this->apiClient->makeRawRequest($request);
            $statusCode = $response->getStatusCode();

            if ($statusCode === 204) {
                return true;
            }

            return false;
        } catch (ClientException $exception) {
            $statusCode = $exception->getResponse()->getStatusCode();

            if ($statusCode === 401) {
                return false;
            }

            throw $exception;
        }
    }
}
