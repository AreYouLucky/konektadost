import PhilippineMap, { type MapLocation, type SearchMapProps } from '@/components/philippine-map';
import { geographyApi, type MapRoutePrefix } from '@/components/philippine-map/types';
import ProvinceResourcesCard from '@/pages/admin/search-map/partials/province-resources-card';
import { useMapMarkers } from '@/pages/admin/search-map/use-map-markers';
import { useProvinceAgriculture } from '@/pages/admin/search-map/use-province-agriculture';
import { useProvinceResources } from '@/pages/admin/search-map/use-province-resources';
import { useMemo, useState } from 'react';

export default function IntelligenceMap({ routePrefix = 'admin.search-map', ...props }: SearchMapProps & { routePrefix?: MapRoutePrefix }) {
    const [path, setPath] = useState<MapLocation[]>([]);
    const selection = path.at(-1) ?? null;
    const province = path.find((location) => location.level === 'province') ?? null;
    const api = useMemo(() => geographyApi(routePrefix), [routePrefix]);
    const resources = useProvinceResources(province?.id ?? null, selection, routePrefix);
    const markers = useMapMarkers(routePrefix);
    const agriculture = useProvinceAgriculture(province?.id ?? null, selection, routePrefix);
    const [informationOpen, setInformationOpen] = useState(true);

    return (
        <div className="relative min-h-[400px] flex-1 overflow-hidden md:rounded-b-xl">
            <PhilippineMap
                {...props}
                api={api}
                className="absolute inset-0 h-full"
                syncUrl
                onSelectionChange={(selected, nextPath) => {
                    if (selected?.key !== selection?.key) setInformationOpen(true);
                    setPath(nextPath);
                }}
                resourcePins={markers.pins}
                resourceLoading={markers.loading}
                resourceError={markers.error}
                onRetryResources={markers.retry}
                rightPanelWidth={province && informationOpen ? 390 : 0}
            >
                {province && selection && (
                    <ProvinceResourcesCard
                        key={selection.key}
                        selection={selection}
                        parents={path.slice(0, -1).map((location) => location.name)}
                        agriculture={agriculture}
                        data={resources.data}
                        loading={resources.loading}
                        error={resources.error}
                        retry={resources.retry}
                        open={informationOpen}
                        onOpenChange={setInformationOpen}
                        assessmentMap={routePrefix === 'admin.search-map' ? 'admin' : 'user'}
                    />
                )}
            </PhilippineMap>
        </div>
    );
}
