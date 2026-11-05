import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppSidebarLayoutAdmin from '@/layouts/app/app-sidebar-layout-admin';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin/dashboard',
    },
];

export default function AdminDashboard() {
    return (
        <AppSidebarLayoutAdmin breadcrumbs={breadcrumbs}>
            <Head title="Admin Dashboard" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-2">
                        <Heading title="Admin Dashboard" description="Restricted area. Access is enforced by the role:admin middleware." />
                        <Badge className="shrink-0">
                            <ShieldCheck className="size-3" />
                            Admin
                        </Badge>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Data maintenance</CardTitle>
                        </CardHeader>
                        <CardContent className="text-muted-foreground flex flex-col gap-2 text-sm">
                            <p>Reference data is maintained through Artisan commands rather than this interface:</p>
                            <ul className="flex list-disc flex-col gap-1 pl-5">
                                <li>
                                    <code>php artisan geo:compute-area</code> — recomputes land area from boundary polygons
                                </li>
                                <li>
                                    <code>php artisan benchmarks:compute</code> — recomputes national reference rates
                                </li>
                            </ul>
                            <p>Screens for managing users and datasets have not been built yet.</p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">What is different here</CardTitle>
                        </CardHeader>
                        <CardContent className="text-muted-foreground flex flex-col gap-2 text-sm">
                            <p>
                                This layout renders a separate admin sidebar rather than sharing one with standard users. The two menus are
                                independent files and can diverge without affecting each other.
                            </p>
                            <p>
                                Non-admin users hitting <code>/admin</code> are redirected to their own dashboard by <code>EnsureUserHasRole</code>.
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppSidebarLayoutAdmin>
    );
}
