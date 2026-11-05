import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FlaskConical } from 'lucide-react';
import type { LocalLaboratoryItem } from './types';

export function LocalLaboratoriesSection({ cityName, laboratories }: { cityName: string; laboratories?: LocalLaboratoryItem[] }) {
    const labs = laboratories ?? [];

    return (
        <section className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                <div className="flex items-center gap-2">
                    <Badge variant="outline" className="font-mono text-xs">
                        Layer B · analyze_sti_laboratory_capacity
                    </Badge>
                    <h2 className="text-lg font-semibold tracking-tight">DOST / OneLab Accredited Laboratories in {cityName}</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Existing testing and calibration laboratories located in {cityName} and their registered testing services.
                </p>
            </div>

            {labs.length === 0 ? (
                <Card>
                    <CardContent className="text-muted-foreground p-6 text-sm">
                        No accredited DOST / OneLab laboratories are currently recorded inside {cityName}. Residents and MSMEs can be referred to the
                        nearest regional OneLab facility.
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {labs.map((lab) => {
                        const services = Array.isArray(lab.services) ? lab.services : [];

                        return (
                            <Card key={lab.id}>
                                <CardHeader className="pb-2">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <FlaskConical className="size-4 shrink-0 text-[#087dcc]" />
                                            <CardTitle className="text-sm leading-snug">{lab.agency_name ?? 'Accredited Laboratory'}</CardTitle>
                                        </div>
                                        <Badge variant="secondary">
                                            {lab.total_services === null ? 'Service count unavailable' : `${lab.total_services} services`}
                                        </Badge>
                                    </div>
                                    {lab.code && <p className="text-muted-foreground font-mono text-xs">{lab.code}</p>}
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3 text-xs">
                                    {services.length > 0 ? (
                                        <div className="flex flex-wrap gap-1.5">
                                            {services.map((svc: any, idx) => (
                                                <Badge key={idx} variant="outline" className="font-normal">
                                                    {svc.test_name || svc.service_name || 'Laboratory Test'}
                                                    {svc.method || svc.description ? ` (${svc.method || svc.description})` : ''}
                                                </Badge>
                                            ))}
                                            {lab.total_services !== null && lab.total_services > services.length && (
                                                <Badge variant="secondary" className="font-normal">
                                                    +{lab.total_services - services.length} more
                                                </Badge>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-muted-foreground">
                                            {lab.services_status === 'unavailable'
                                                ? 'Normalized service details are unavailable; any count shown is source-listed.'
                                                : 'No specific test rows are recorded for this laboratory.'}
                                        </p>
                                    )}
                                    {(lab.contact_information || lab.website) && (
                                        <div className="text-muted-foreground border-t pt-2">
                                            {lab.contact_information && <p>{lab.contact_information}</p>}
                                            {lab.website && (
                                                <a href={lab.website} target="_blank" rel="noreferrer" className="text-[#087dcc] underline">
                                                    {lab.website}
                                                </a>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
