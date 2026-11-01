<?php

namespace App\Support\Assessment;

use App\FunctionTools\LayerA\GetAvailableDatasetsTool;
use App\FunctionTools\LayerA\GetGeographicDataAvailabilityTool;
use App\FunctionTools\LayerA\GetLocalAssetsTool;
use App\FunctionTools\LayerA\GetTelecomInfrastructureTool;
use Illuminate\Database\Eloquent\Model;

/**
 * Facade/adapter over the Layer A Function Tools:
 * - A3: GetAvailableDatasetsTool (`get_available_datasets`)
 * - A4: GetLocalAssetsTool (`get_local_assets`)
 * - A5: GetTelecomInfrastructureTool (`get_telecom_infrastructure`)
 * - A6: GetGeographicDataAvailabilityTool (`get_geographic_data_availability`)
 */
class DatasetAndAssetService
{
    public function __construct(
        private readonly GetAvailableDatasetsTool $datasetsTool,
        private readonly GetLocalAssetsTool $assetsTool,
        private readonly GetTelecomInfrastructureTool $telecomTool,
        private readonly GetGeographicDataAvailabilityTool $geoTool,
    ) {}

    /**
     * Audit all domain tables for a selected location (`A3. get_available_datasets`).
     *
     * @return array<string, mixed>
     */
    public function getAvailableDatasets(Model $location): array
    {
        return $this->datasetsTool->forLocation($location);
    }

    /**
     * Retrieve the local asset inventory for a location (`A4. get_local_assets`).
     *
     * @return array<string, mixed>
     */
    public function getLocalAssets(Model $location, bool $includeFacilityRecords = false): array
    {
        return $this->assetsTool->forLocation($location, $includeFacilityRecords);
    }

    /**
     * Retrieve telecom infrastructure details for a location (`A5. get_telecom_infrastructure`).
     *
     * @return array<string, mixed>
     */
    public function getTelecomInfrastructure(Model $location, bool $includeSiteRecords = false): array
    {
        return $this->telecomTool->forLocation($location, $includeSiteRecords);
    }

    /**
     * Retrieve geographic boundary, centroid, area, and road network availability (`A6. get_geographic_data_availability`).
     *
     * @return array<string, mixed>
     */
    public function getGeographicDataAvailability(Model $location): array
    {
        return $this->geoTool->forLocation($location);
    }

    public function facilityQueryFor(Model $location)
    {
        return $this->datasetsTool->facilityQueryFor($location);
    }

    public function laboratoryQueryFor(Model $location)
    {
        return $this->datasetsTool->laboratoryQueryFor($location);
    }

    public function telecomQueryFor(Model $location)
    {
        return $this->datasetsTool->telecomQueryFor($location);
    }

    public function agricultureQueryFor(Model $location)
    {
        return $this->datasetsTool->agricultureQueryFor($location);
    }
}
