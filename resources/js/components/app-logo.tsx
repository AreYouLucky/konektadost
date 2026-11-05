import { MapPin } from 'lucide-react';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg bg-teal-600 text-white shadow-xs">
                <MapPin className="size-4" />
            </div>
            <div className="ml-1.5 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-bold tracking-tight">KonektaDOST</span>
                <span className="text-muted-foreground truncate text-[10px] leading-none">Spatial STI Platform</span>
            </div>
        </>
    );
}
