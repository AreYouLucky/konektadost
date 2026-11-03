import IntelligenceMap from '@/components/intelligence-map';
import type { SearchMapProps } from '@/components/philippine-map';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { Head } from '@inertiajs/react';

export default function IntelligenceMapPage(props: SearchMapProps) {
    return (
        <AppSidebarLayout breadcrumbs={[{ title: 'Intelligence Map', href: route('user.intelligence-map.index') }]}>
            <Head title="Intelligence Map | KonektaDOST">
                <meta
                    name="description"
                    content="Explore Philippine provinces, cities, municipalities, and barangays with local resources and development indicators."
                />
            </Head>
            <IntelligenceMap {...props} routePrefix="user.intelligence-map" />
        </AppSidebarLayout>
    );
}
