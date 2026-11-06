import IntelligenceMap from '@/components/intelligence-map';
import type { SearchMapProps } from '@/components/philippine-map';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { Head } from '@inertiajs/react';

/** Same page as the user Intelligence Map, inside the admin shell and on the admin endpoints. */
export default function SearchMap(props: SearchMapProps) {
    return (
        <AppSidebarLayoutAdmin breadcrumbs={[{ title: 'Intelligence Map', href: route('admin.search-map.index') }]}>
            <Head title="Intelligence Map | KonektaDOST">
                <meta
                    name="description"
                    content="Explore Philippine provinces, cities, municipalities, and barangays with local resources and development indicators."
                />
            </Head>
            <IntelligenceMap {...props} routePrefix="admin.search-map" />
        </AppSidebarLayoutAdmin>
    );
}
