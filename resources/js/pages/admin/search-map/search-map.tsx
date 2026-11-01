import PhilippineMap, { type MapLocation, type SearchMapProps } from '@/components/philippine-map';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ProvinceResourcesCard from './partials/province-resources-card';
import { useMapMarkers } from './use-map-markers';
import { useProvinceAgriculture } from './use-province-agriculture';
import { useProvinceResources } from './use-province-resources';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Intelligence Map',
        href: '/admin/search-map',
    },
];

export default function SearchMap(props: SearchMapProps) {
    const [path, setPath] = useState<MapLocation[]>([]);
    const selection = path.at(-1) ?? null;
    const province = path.find((location) => location.level === 'province') ?? null;
    const resources = useProvinceResources(province?.id ?? null, selection);
    const markers = useMapMarkers();
    const agriculture = useProvinceAgriculture(province?.id ?? null, selection);
    const [informationOpen, setInformationOpen] = useState(true);

    return (
        <AppSidebarLayoutAdmin breadcrumbs={breadcrumbs}>
            <Head title="Explore the Philippines | KonektaDOST">
                <meta
                    name="description"
                    content="Explore Philippine provinces, cities, municipalities, and barangays in an immersive geographic map."
                />
            </Head>
            <div className="relative min-h-[400px] flex-1 overflow-hidden md:rounded-b-xl">
                <PhilippineMap
                    {...props}
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
                        />
                    )}
                </PhilippineMap>
            </div>
        </AppSidebarLayoutAdmin>
    );
}
