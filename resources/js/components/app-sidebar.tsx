import { NavFooter } from '@/components/nav-footer';
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
    SidebarTrigger,
} from '@/components/ui/sidebar';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Folder, LayoutGrid, MapPinned } from 'lucide-react';
import AppLogo from './app-logo';

/**
 * Sidebar for standard (non-admin) users.
 *
 * Deliberately self-contained: the menu is declared and rendered inline rather
 * than through a shared nav component, so this file can be restructured without
 * touching the admin sidebar. See app-sidebar-admin.tsx.
 */
export function AppSidebar() {
    const page = usePage();

    // Compare pathnames only, because pages that keep state in query params
    // (the map uses ?province=...&city=...) would otherwise never match.
    const currentPath = page.url.split('?')[0].split('#')[0];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem className="flex items-center gap-1 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-2">
                        <SidebarMenuButton size="lg" asChild className="min-w-0 flex-1 group-data-[collapsible=icon]:flex-none">
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                        <SidebarTrigger className="size-8 shrink-0 cursor-pointer rounded-lg" />
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup className="px-2 py-0">
                    <SidebarGroupLabel>Platform</SidebarGroupLabel>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild isActive={currentPath === '/dashboard'}>
                                <Link href="/dashboard" prefetch>
                                    <LayoutGrid />
                                    <span>Dashboard</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>

                        <SidebarMenuItem>
                            <SidebarMenuButton asChild isActive={currentPath === '/location'}>
                                <Link href="/location" prefetch>
                                    <MapPinned />
                                    <span>Service Area Map</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <NavFooter
                    items={[
                        {
                            title: 'Repository',
                            url: 'https://github.com/laravel/react-starter-kit',
                            icon: Folder,
                        },
                        {
                            title: 'Documentation',
                            url: 'https://laravel.com/docs/starter-kits',
                            icon: BookOpen,
                        },
                    ]}
                    className="mt-auto"
                />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
