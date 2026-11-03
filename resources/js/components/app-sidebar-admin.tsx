import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarSeparator,
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    ChevronRight,
    ClipboardList,
    FileUp,
    Globe2,
    LayoutGrid,
    LockKeyhole,
    MapPin,
    MapPinned,
    ShieldCheck,
    Users,
} from 'lucide-react';

const workspaceItems = [
    { title: 'Overview', href: '/admin/dashboard', icon: LayoutGrid },
    { title: 'Intelligence Map', href: '/admin/search-map', icon: Globe2 },
    { title: 'Location Assessment', href: '/admin/assessment', icon: ClipboardList },
    { title: 'Service Area Map', href: '/location', icon: MapPinned },
];

/** Admin navigation is kept separate from the user sidebar. */
export function AppSidebarAdmin() {
    const { url } = usePage();
    const currentPath = url.split('?')[0].split('#')[0];

    return (
        <Sidebar collapsible="icon" variant="inset" className="admin-sidebar">
            <SidebarHeader className="gap-4 p-4 group-data-[collapsible=icon]:gap-0 group-data-[collapsible=icon]:p-2">
                <SidebarMenu>
                    <SidebarMenuItem className="flex items-center gap-1 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-2">
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            tooltip="KonektaDOST · Admin overview"
                            className="h-12 min-w-0 flex-1 rounded-xl p-0 group-data-[collapsible=icon]:flex-none hover:bg-blue-50"
                        >
                            <Link href="/admin/dashboard" prefetch>
                                <div className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-blue-100 bg-blue-50 text-[#087dcc] group-data-[collapsible=icon]:size-8 group-data-[collapsible=icon]:rounded-lg">
                                    <MapPin className="size-5" strokeWidth={1.8} aria-hidden="true" />
                                </div>
                                <span className="ml-1 flex min-w-0 flex-col gap-1 group-data-[collapsible=icon]:hidden">
                                    <span className="truncate text-base font-semibold tracking-tight text-slate-900">KonektaDOST</span>
                                    <span className="text-[10px] tracking-wide text-slate-500">Spatial STI Platform</span>
                                </span>
                            </Link>
                        </SidebarMenuButton>
                        <SidebarTrigger className="size-8 shrink-0 cursor-pointer rounded-lg text-slate-500 hover:bg-blue-50 hover:text-[#087dcc] focus-visible:ring-[#087dcc]" />
                    </SidebarMenuItem>
                </SidebarMenu>
                <div className="flex items-center justify-between group-data-[collapsible=icon]:hidden">
                    <span className="inline-flex items-center gap-1.5 rounded-full border border-blue-100 bg-blue-50 px-2.5 py-1 text-[10px] font-medium text-[#087dcc]">
                        <ShieldCheck className="size-3" aria-hidden="true" /> Administrator
                    </span>
                    <span className="text-[9px] tracking-widest text-slate-400 uppercase">Workspace</span>
                </div>
            </SidebarHeader>

            <SidebarSeparator className="mx-4 bg-slate-200 group-data-[collapsible=icon]:mx-2" />

            <SidebarContent className="gap-5 pt-3">
                <SidebarGroup className="px-3 py-0 group-data-[collapsible=icon]:px-2">
                    <SidebarGroupLabel className="mb-1 px-3 text-[10px] font-medium tracking-[0.16em] text-slate-500 uppercase">
                        Explore & assess
                    </SidebarGroupLabel>
                    <SidebarMenu className="gap-1.5">
                        {workspaceItems.map((item) => {
                            const active = currentPath === item.href || (item.href === '/admin/assessment' && currentPath === '/location/assess');
                            return (
                                <SidebarMenuItem key={item.href}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={active}
                                        tooltip={item.title}
                                        className="h-11 rounded-xl px-3 text-[13px] font-medium text-slate-600 transition-colors hover:bg-blue-50 hover:text-[#087dcc] data-[active=true]:bg-[#087dcc]/10 data-[active=true]:font-semibold data-[active=true]:text-[#087dcc] [&>svg]:size-[18px]"
                                    >
                                        <Link href={item.href} aria-current={active ? 'page' : undefined}>
                                            <item.icon strokeWidth={1.7} aria-hidden="true" />
                                            <span>{item.title}</span>
                                            {active && (
                                                <ChevronRight
                                                    className="ml-auto size-3.5! opacity-60 group-data-[collapsible=icon]:hidden"
                                                    aria-hidden="true"
                                                />
                                            )}
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>

                <SidebarGroup className="px-3 py-0 group-data-[collapsible=icon]:px-2">
                    <SidebarGroupLabel className="mb-1 px-3 text-[10px] font-medium tracking-[0.16em] text-slate-500 uppercase">
                        Reference data
                    </SidebarGroupLabel>
                    <SidebarMenu className="gap-1.5">
                        {[
                            { title: 'Population', icon: Users },
                            { title: 'Data import', icon: FileUp },
                        ].map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    disabled
                                    tooltip={`${item.title} · Not enabled`}
                                    title={`${item.title} management is not enabled`}
                                    className="h-10 rounded-xl px-3 text-[13px] text-slate-400 disabled:opacity-50 [&>svg]:size-[18px]"
                                >
                                    <item.icon strokeWidth={1.7} aria-hidden="true" />
                                    <span>{item.title}</span>
                                    <LockKeyhole className="ml-auto size-3! opacity-70 group-data-[collapsible=icon]:hidden" aria-hidden="true" />
                                    <span className="sr-only">Not enabled</span>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                    <p className="mt-2 px-3 text-[10px] leading-relaxed text-slate-500 group-data-[collapsible=icon]:hidden">
                        Data management is not enabled.
                    </p>
                </SidebarGroup>

                <div className="mx-4 mt-auto rounded-2xl border border-blue-100 bg-blue-50/60 p-4 group-data-[collapsible=icon]:hidden">
                    <Globe2 className="mb-2.5 size-5 text-[#087dcc]" strokeWidth={1.5} aria-hidden="true" />
                    <p className="text-xs font-medium text-slate-700">Evidence for local decisions</p>
                    <p className="mt-1.5 text-[11px] leading-relaxed text-slate-500">Explore communities, services and STI resources in one place.</p>
                    <Link
                        href="/admin/search-map"
                        className="mt-3 inline-flex min-h-8 items-center gap-1.5 text-[11px] font-semibold text-[#087dcc] hover:text-[#087dcc]/80 focus-visible:rounded focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#087dcc]"
                    >
                        Open intelligence map <ArrowUpRight className="size-3.5" aria-hidden="true" />
                    </Link>
                </div>
            </SidebarContent>

            <SidebarFooter className="mt-3 border-t border-slate-200 p-3 group-data-[collapsible=icon]:p-2 [&_[data-sidebar=menu-button]]:rounded-xl [&_[data-sidebar=menu-button]]:border [&_[data-sidebar=menu-button]]:border-slate-200 [&_[data-sidebar=menu-button]]:bg-slate-50 [&_[data-sidebar=menu-button]]:text-slate-700 [&_[data-sidebar=menu-button]]:hover:bg-blue-50">
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
