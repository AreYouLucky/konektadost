import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <header aria-label="Mobile navigation" className="flex h-12 shrink-0 items-center gap-2 border-b border-slate-200 px-3 md:hidden">
            <SidebarTrigger className="size-9 shrink-0 text-[#123575] hover:bg-blue-50" />
            <div className="min-w-0 flex-1 overflow-hidden [&_li:last-child]:truncate [&_ol]:flex-nowrap">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
