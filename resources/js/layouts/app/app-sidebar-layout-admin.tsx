import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarAdmin } from '@/components/app-sidebar-admin';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { type BreadcrumbItem } from '@/types';

/**
 * Shell for administrator pages. Identical to AppSidebarLayout except that it
 * renders the separate admin sidebar, so the two layouts can diverge.
 */
export default function AppSidebarLayoutAdmin({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        <AppShell variant="sidebar">
            <AppSidebarAdmin />
            <AppContent variant="sidebar" className="admin-content bg-blue-50/70 dark:bg-slate-950">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
