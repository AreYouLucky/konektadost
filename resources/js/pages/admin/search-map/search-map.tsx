import IntelligenceMap from '@/components/intelligence-map';
import type { SearchMapProps } from '@/components/philippine-map';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Intelligence Map',
        href: '/admin/search-map',
    },
];

export default function SearchMap(props: SearchMapProps) {
    return (
        <AppSidebarLayoutAdmin breadcrumbs={breadcrumbs}>
            <Head title="Explore the Philippines | KonektaDOST">
                <meta
                    name="description"
                    content="Explore Philippine provinces, cities, municipalities, and barangays in an immersive geographic map."
                />
            </Head>
            <IntelligenceMap {...props} />
        </AppSidebarLayoutAdmin>
    );
}
